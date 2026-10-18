// Standalone root-private read-only collector. Compile alone, not into the SDK.
// It issues no authority and never advances a case, releases a barrier or writes SQL.
#include "authority_bridge.cpp"
#include "reader_core.hpp"
#include <mysql/mysql.h>
#include <openssl/crypto.h>
#include <iostream>
namespace paymenter_qa {
namespace {
File credential_file(const std::string& path,Owner owner) {
    canonical(path);auto slash=path.rfind('/');
    Fd parent(directory_fd(path.substr(0,slash),owner,false));
    Fd fd(openat(parent.fd,path.substr(slash+1).c_str(),O_RDONLY|O_NOFOLLOW|O_CLOEXEC|O_NONBLOCK));
    struct stat before,after;
    guard(fstat(fd.fd,&before)==0 && S_ISREG(before.st_mode) && before.st_uid==owner.uid && before.st_gid==owner.gid &&
        before.st_nlink==1 && ((before.st_mode&07777)==0400 || (before.st_mode&07777)==0600) &&
        before.st_size>0 && before.st_size<=1024,"reader-credential-custody");
    auto raw=bounded(fd.fd,1024);
    guard(fstat(fd.fd,&after)==0 && stamp(before)==stamp(after) && raw.size()==static_cast<size_t>(before.st_size),"reader-credential-changed");
    return {raw,stamp(before)};
}
std::string credential_password(const std::string& raw) {
    // This qualified channel is a simple existing [client]/password file. Never
    // invoke a defaults parser which could load includes, plugins or init SQL.
    auto trim=[](const std::string& value) {
        auto first=value.find_first_not_of(" \t"),last=value.find_last_not_of(" \t");
        return first==std::string::npos?std::string():value.substr(first,last-first+1);
    };
    std::istringstream input(raw);std::string line,password;bool section=false,found=false;
    while(std::getline(input,line)) {
        if(!line.empty() && line.back()=='\r')line.pop_back();
        line=trim(line);
        if(line.empty() || line[0]=='#' || line[0]==';')continue;
        if(!section){guard(line=="[client]","reader-credential-format");section=true;continue;}
        auto equals=line.find('=');
        guard(!found && equals!=std::string::npos && trim(line.substr(0,equals))=="password","reader-credential-format");
        password=trim(line.substr(equals+1));found=true;
        guard(!password.empty() && password.size()<=256,"reader-credential-format");
        if(password[0]=='\'' || password[0]=='\"') {
            guard(password.size()>=3 && password.back()==password[0],"reader-credential-format");
            password=password.substr(1,password.size()-2);
        }
        for(unsigned char c:password)guard(c>32 && c<127 && c!='\\' && c!='\'' && c!='\"' && c!='#' && c!=';',"reader-credential-format");
    }
    guard(section && found,"reader-credential-format");return password;
}
class NativeReadDatabase:public ReadDatabase {
    std::unique_ptr<MYSQL,decltype(&mysql_close)> db_;
public:
    NativeReadDatabase(const std::string& password,const std::string& database,const std::string& socket):
        db_(mysql_init(nullptr),mysql_close) {
        read_guard(bool(db_),"reader-client-init");
        unsigned timeout=2,protocol=MYSQL_PROTOCOL_SOCKET,disabled=0;my_bool reconnect=0;
        for(auto option:{MYSQL_OPT_CONNECT_TIMEOUT,MYSQL_OPT_READ_TIMEOUT,MYSQL_OPT_WRITE_TIMEOUT})
            read_guard(mysql_options(db_.get(),option,&timeout)==0,"reader-client-options");
        read_guard(mysql_options(db_.get(),MYSQL_OPT_PROTOCOL,&protocol)==0 &&
            mysql_options(db_.get(),MYSQL_OPT_LOCAL_INFILE,&disabled)==0 &&
            mysql_options(db_.get(),MYSQL_OPT_RECONNECT,&reconnect)==0,"reader-client-options");
        // No defaults, environment-selected host, remote TCP, plugins/init SQL,
        // multi-statements or auto-reconnect. Password never reaches argv/output.
        read_guard(mysql_real_connect(db_.get(),"localhost","root",password.c_str(),database.c_str(),0,socket.c_str(),0)!=nullptr,"reader-connect");
    }
    void configure() override {
        const std::string sql="SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED";
        read_guard(mysql_real_query(db_.get(),sql.data(),sql.size())==0 && mysql_autocommit(db_.get(),1)==0,"reader-configure");
    }
    ReadRows select(const std::string& sql) override {
        read_guard(mysql_real_query(db_.get(),sql.data(),sql.size())==0,"reader-query");
        std::unique_ptr<MYSQL_RES,decltype(&mysql_free_result)> result(mysql_store_result(db_.get()),mysql_free_result);
        read_guard(bool(result),"reader-result");
        auto columns=mysql_num_fields(result.get());
        read_guard(columns>0 && columns<=5 && mysql_num_rows(result.get())<=3,"reader-result-bound");
        ReadRows rows;size_t total=0;MYSQL_ROW row;
        while((row=mysql_fetch_row(result.get()))!=nullptr) {
            auto lengths=mysql_fetch_lengths(result.get());read_guard(lengths!=nullptr,"reader-result");
            std::vector<std::string> values;
            for(unsigned i=0;i<columns;++i) {
                read_guard(row[i]!=nullptr && lengths[i]<=4096,"reader-cell");total+=lengths[i];
                read_guard(total<=16384,"reader-result-bound");values.emplace_back(row[i],lengths[i]);
            }
            rows.push_back(values);
        }
        read_guard(mysql_errno(db_.get())==0,"reader-fetch");return rows;
    }
    ReaderState state() override {
        auto r=select("SELECT CONNECTION_ID(),@@session.tx_isolation,@@session.autocommit,DATABASE(),@@socket");
        read_guard(r.size()==1 && r[0].size()==5 && r[0][0]==std::to_string(mysql_thread_id(db_.get())),"reader-session");
        return {r[0][0],r[0][1],r[0][3],r[0][4],r[0][2],db_->server_status};
    }
};
void perform_native_read(const std::shared_ptr<PrivateDirectory>& dir,const std::string& credentials,Owner owner,
                         const std::string& database,const std::string& socket,const std::function<std::string()>& boot) {
    for(const auto& name:{"read.started.original","read.original","read.complete.original","read.failure.original"})
        guard(!dir->exists(name),"reader-reused-operation");
    auto original=dir->read("read.approval.native.original",4096);
    auto f=parse(original.raw,"paymenter-source-qa-read-approval-native-v1","intent kind run request boot case phase facts writer previous issued expires");
    guard(f.at("intent")=="observe-framework" && hex64(f.at("run")) && hex64(f.at("request")) && hex64(f.at("facts")),"reader-approval");
    auto issued=number(f.at("issued")),expires=number(f.at("expires"));auto now=now_seconds();
    guard(issued<expires && expires-issued<=30 && now>=issued && now<expires,"reader-expiry");
    auto end=Clock::now()+std::chrono::seconds(expires-now);
    const pid_t process=getpid();
    auto current=[&] {
        guard(getpid()==process && boot()==f.at("boot") && now_seconds()>=issued && now_seconds()<expires && Clock::now()<end,"reader-current");
        guard(same_file(original,dir->read("read.approval.native.original",4096)),"reader-approval-changed");
        guard(!dir->exists("revoked.v1") && !dir->exists("retired.v1"),"reader-revoked");
    };
    current();ReaderRequest request{f.at("run"),f.at("case"),f.at("facts"),f.at("writer"),{}};
    if(f.at("kind")=="channel")guard(f.at("case")=="none" && f.at("phase")=="none" && f.at("writer")=="none" && f.at("previous")=="none","reader-kind");
    else {
        guard(f.at("kind")=="observation" && (f.at("phase")=="before" || f.at("phase")=="barrier" || f.at("phase")=="after" || f.at("phase")=="retired"),"reader-kind");
        if(f.at("previous")!="none") {
            std::istringstream values(f.at("previous"));std::string value;
            while(std::getline(values,value,','))request.previous.push_back(value);
            guard(!f.at("previous").empty() && f.at("previous").back()!=',',"reader-history");
        }
    }
    dir->create("read.started.original","paymenter-source-qa-read-started-native-v1\nrequest="+f.at("request")+"\napproval_sha256="+hash(original.raw)+"\n");
    try {
        auto credential=credential_file(credentials,owner);auto password=credential_password(credential.raw);current();
        NativeReadDatabase db(password,database,socket);
        OPENSSL_cleanse(&password[0],password.size());password.clear();
        guard(same_file(credential,credential_file(credentials,owner)),"reader-credential-changed");current();
        CommittedRead reader(db,database,socket,end);std::string connection;ReadRows rows;
        auto started=now_seconds();
        if(f.at("kind")=="channel")connection=reader.channel().connection;
        else {auto result=reader.observe(request);connection=result.connection;rows=result.rows;}
        auto finished=now_seconds();
        std::string raw="paymenter-source-qa-read-original-native-v1\nkind="+f.at("kind")+"\nrun="+f.at("run")+"\nrequest="+f.at("request")+
            "\nboot="+f.at("boot")+"\ncase="+f.at("case")+"\nphase="+f.at("phase")+"\nfacts="+f.at("facts")+"\nwriter="+f.at("writer")+
            "\nconnection="+connection+"\nstarted="+std::to_string(started)+"\nfinished="+std::to_string(finished)+
            "\nisolation=READ COMMITTED\nautocommit=1\ntransaction_open=0\napproval_sha256="+hash(original.raw)+"\n";
        for(const auto& row:rows)raw+="row="+row[0]+","+row[1]+","+row[2]+","+row[3]+","+row[4]+"\n";
        dir->create("read.original",raw);
        guard(same_file(credential,credential_file(credentials,owner)),"reader-credential-changed");current();
        dir->create("read.complete.original","paymenter-source-qa-read-complete-native-v1\nrequest="+f.at("request")+"\noriginal_sha256="+hash(raw)+"\n");
        current();
    }catch(...) {
        if(!dir->exists("read.failure.original"))dir->create("read.failure.original","paymenter-source-qa-read-incomplete-native-v1\nrequest="+f.at("request")+"\n");
        throw;
    }
}
}
}
#ifndef PAYMENTER_QA_READER_NO_MAIN
#if !defined(PAYMENTER_QA_CREDENTIAL_FILE) || !defined(PAYMENTER_QA_DATABASE) || !defined(PAYMENTER_QA_SOCKET)
#error "Exact reviewed credential file, database and local socket are required"
#endif
int main(int argc,char**argv) {
    using namespace paymenter_qa;
    try {
#ifdef __linux__
        guard(argc==2 && geteuid()==0 && getegid()==0,"reader-linux-root-required");
        auto dir=std::make_shared<PrivateDirectory>(argv[1],Owner{0,0});
        struct statfs fs;guard(fstatfs(dir->descriptor(),&fs)==0 &&
            (fs.f_type==0xEF53 || fs.f_type==0x58465342 || fs.f_type==0x01021994),"reader-filesystem");
        perform_native_read(dir,PAYMENTER_QA_CREDENTIAL_FILE,Owner{0,0},PAYMENTER_QA_DATABASE,PAYMENTER_QA_SOCKET,boot_id);
        std::cout<<"native-read-retained\n";return 0;
#else
        (void)argc;(void)argv;throw ProbeError("reader-linux-root-required");
#endif
    }catch(const ProbeError& e){std::cerr<<e.what()<<'\n';}
    catch(const ReaderError& e){std::cerr<<e.what()<<'\n';}
    catch(...){std::cerr<<"reader-failed\n";}
    return 1;
}
#endif
