// Standalone observational collector. Never linked into the SDK/bootstrap.
#include "authority_bridge.cpp"
#include "manual_financial_reader_core.hpp"
#include "credential_channel.hpp"
#include <mysql/mysql.h>
#include <openssl/crypto.h>
#include <sys/resource.h>
#include <iostream>
namespace paymenter_qa { namespace {
class ManualReadImage {
    std::string path_,parent_,leaf_,digest_;Owner owner_;Fd fd_;Stamp original_;
    static int open_image(const std::string& path,Owner owner){canonical(path);auto slash=path.rfind('/');Fd parent(directory_fd(path.substr(0,slash),owner,false));return openat(parent.fd,path.substr(slash+1).c_str(),O_RDONLY|O_NOFOLLOW|O_CLOEXEC|O_NONBLOCK);}
public:
    ManualReadImage(const std::string& path,Owner owner,bool executable):path_(path),owner_(owner),fd_(open_image(path,owner)) {
        // Image aliases need separate native qualification; private controls never follow links.
        canonical(path_);auto slash=path_.rfind('/');parent_=path_.substr(0,slash);leaf_=path_.substr(slash+1);
        struct stat before,after;guard(fstat(fd_.fd,&before)==0 && S_ISREG(before.st_mode) && before.st_uid==owner_.uid && before.st_gid==owner_.gid && !(before.st_mode&0022) && (!executable || (before.st_mode&0111)) && before.st_nlink>0 && before.st_size>0 && before.st_size<=67108864,"manual-read-image");
        auto raw=bounded(fd_.fd,67108864);guard(fstat(fd_.fd,&after)==0 && stamp(before)==stamp(after) && raw.size()==static_cast<size_t>(before.st_size),"manual-read-image-changed");original_=stamp(after);digest_=hash(raw);unchanged();
    }
    void unchanged()const{Fd dir(directory_fd(parent_,owner_,false));struct stat named,held;guard(fstatat(dir.fd,leaf_.c_str(),&named,AT_SYMLINK_NOFOLLOW)==0 && fstat(fd_.fd,&held)==0 && stamp(named)==original_ && stamp(held)==original_,"manual-read-image-changed");}
    const std::string& digest()const{return digest_;}
};
class ManualReadImages {
    ManualReadImage runtime_,library_,reader_;
public:
    ManualReadImages(std::string runtime,std::string library,std::string reader,Owner owner):runtime_(runtime,owner,true),library_(library,owner,false),reader_(reader,owner,true){}
    std::string current()const{runtime_.unchanged();library_.unchanged();reader_.unchanged();return "paymenter-manual-read-identity-v1\nruntime_sha256="+runtime_.digest()+"\nlibrary_sha256="+library_.digest()+"\nreader_sha256="+reader_.digest()+"\n";}
};
class ManualReadDatabase:public ReadDatabase {
    std::unique_ptr<MYSQL,decltype(&mysql_close)> db_;std::function<void()> current_;
public:
    ManualReadDatabase(const std::string& password,const std::string& database,const std::string& socket,std::function<void()> current):db_(mysql_init(nullptr),mysql_close),current_(std::move(current)) {
        current_();read_guard(bool(db_),"manual-read-client");unsigned timeout=2,protocol=MYSQL_PROTOCOL_SOCKET,disabled=0;my_bool reconnect=0;
        for(auto option:{MYSQL_OPT_CONNECT_TIMEOUT,MYSQL_OPT_READ_TIMEOUT,MYSQL_OPT_WRITE_TIMEOUT})read_guard(mysql_options(db_.get(),option,&timeout)==0,"manual-read-client-options");
        read_guard(mysql_options(db_.get(),MYSQL_OPT_PROTOCOL,&protocol)==0 && mysql_options(db_.get(),MYSQL_OPT_LOCAL_INFILE,&disabled)==0 && mysql_options(db_.get(),MYSQL_OPT_RECONNECT,&reconnect)==0,"manual-read-client-options");
        read_guard(mysql_real_connect(db_.get(),"localhost","root",password.c_str(),database.c_str(),0,socket.c_str(),0)!=nullptr,"manual-read-connect");current_();
        auto principal=select("SELECT CURRENT_USER()");read_guard(principal.size()==1 && principal[0].size()==1 && principal[0][0].compare(0,5,"root@")==0,"manual-read-principal");
    }
    void configure()override{current_();const std::string sql="SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED";read_guard(mysql_real_query(db_.get(),sql.data(),sql.size())==0 && mysql_autocommit(db_.get(),1)==0,"manual-read-configure");current_();}
    ReadRows select(const std::string& sql)override {
        current_();read_guard(sql.size()<=65536,"manual-read-query-bound");read_guard(mysql_real_query(db_.get(),sql.data(),sql.size())==0,"manual-read-query");
        std::unique_ptr<MYSQL_RES,decltype(&mysql_free_result)> res(mysql_store_result(db_.get()),mysql_free_result);read_guard(bool(res),"manual-read-result");
        const bool observation=sql.find("SELECT 'subaccount'")==0,ddl=sql.find("SHOW CREATE TABLE ")==0;
        const size_t row_cap=observation?128:512,cell_cap=ddl?32768:observation?256:256,total_cap=observation?65536:262144;
        unsigned columns=mysql_num_fields(res.get());read_guard(columns>0 && columns<=5 && mysql_num_rows(res.get())<row_cap,"manual-read-result-bound");ReadRows out;size_t total=0;MYSQL_ROW row;
        while((row=mysql_fetch_row(res.get()))!=nullptr){auto lengths=mysql_fetch_lengths(res.get());read_guard(lengths!=nullptr,"manual-read-result");std::vector<std::string> cells;for(unsigned i=0;i<columns;++i){read_guard(row[i]!=nullptr && lengths[i]<=cell_cap,"manual-read-cell");total+=lengths[i];read_guard(total<=total_cap,"manual-read-result-bound");cells.emplace_back(row[i],lengths[i]);}out.push_back(cells);}
        read_guard(mysql_errno(db_.get())==0,"manual-read-fetch");current_();return out;
    }
    ReaderState state()override{auto r=select("SELECT CONNECTION_ID(),@@session.tx_isolation,@@session.autocommit,DATABASE(),@@socket");read_guard(r.size()==1 && r[0].size()==5 && r[0][0]==std::to_string(mysql_thread_id(db_.get())),"manual-read-session");return {r[0][0],r[0][1],r[0][3],r[0][4],r[0][2],db_->server_status};}
};
std::vector<std::string> manual_split(const std::string& raw,char delimiter) {
    guard(!raw.empty() && raw.back()!=delimiter,"manual-read-list");std::istringstream in(raw);std::string part;std::vector<std::string> out;
    while(std::getline(in,part,delimiter)){guard(!part.empty() && out.size()<512,"manual-read-list");out.push_back(part);}return out;
}
void perform_manual_financial_read(const std::shared_ptr<PrivateDirectory>& dir,const std::string& credentials,Owner owner,const std::string& database,const std::string& socket,const std::function<std::string()>& boot,const std::function<std::string()>& images) {
    struct rlimit core={0,0};guard(setrlimit(RLIMIT_CORE,&core)==0,"manual-read-core-dump");
    for(const auto& name:{"manual.read.started.original","manual.read.original","manual.read.complete.original","manual.read.failure.original"})guard(!dir->exists(name),"manual-read-reused");
    auto approval=dir->read("manual.read.approval.original",4096);
    auto a=parse(approval.raw,"paymenter-manual-financial-read-approval-v1","intent run request boot actor_hex case journal_case phase facts writer previous registry_sha256 preimage_sha256 schema_sha256 foreign_keys_sha256 writer_sha256 identity_sha256 issued expires");
    guard(a.at("intent")=="observe-manual-financial" && hex64(a.at("request")),"manual-read-purpose");
    (void)actor(a.at("actor_hex"));auto issued=number(a.at("issued")),expires=number(a.at("expires")),now=now_seconds();
    guard(issued<expires && expires-issued<=30 && now>=issued && now<expires,"manual-read-expiry");auto end=Clock::now()+std::chrono::seconds(expires-now);pid_t process=getpid();
    const std::vector<std::pair<std::string,std::string>> inputs={{"registry","manual.registry.original"},{"preimage","manual.preimage.original"},{"schema","manual.schema.original"},{"foreign_keys","manual.foreign_keys.original"},{"writer","manual.writer.original"},{"identity","manual.identity.original"}};
    std::vector<std::pair<std::string,size_t>> names={{"manual.read.approval.original",4096}};for(const auto& item:inputs)names.emplace_back(item.second,item.first=="foreign_keys"?65536:4096);
    for(const auto& t:manual_financial_tables())names.emplace_back("manual.schema."+t+".original",32768);
    auto originals=dir->read_batch(names);std::map<std::string,File> files;for(size_t i=0;i<names.size();++i)files.emplace(names[i].first,originals[i]);
    guard(same_file(approval,files.at("manual.read.approval.original")),"manual-read-approval-changed");
    for(const auto& input:inputs)guard(hex64(a.at(input.first+"_sha256")) && hash(files.at(input.second).raw)==a.at(input.first+"_sha256"),"manual-read-original-hash");
    const auto reg=parse(files.at("manual.registry.original").raw,"paymenter-manual-financial-registry-v1","run boot actor_hex facts account subaccount project currency method module payments users runtime_sha256 library_sha256 reader_sha256");
    const auto pre=parse(files.at("manual.preimage.original").raw,"paymenter-manual-financial-preimage-v1","run boot facts account subaccount project currency method payments users balance creation_sha256");
    const auto writer=parse(files.at("manual.writer.original").raw,"paymenter-manual-financial-writer-v1","run boot actor_hex case payment facts connection pid start runtime_sha256 library_sha256");
    const auto identity=parse(files.at("manual.identity.original").raw,"paymenter-manual-read-identity-v1","runtime_sha256 library_sha256 reader_sha256");
    for(const auto& k:{"run","boot","actor_hex","facts"})guard(reg.at(k)==a.at(k),"manual-read-registry-binding");
    for(const auto& k:{"run","boot","facts","account","subaccount","project","currency","method","payments","users"})guard(pre.at(k)==reg.at(k),"manual-read-preimage-binding");
    (void)manual_financial_units(pre.at("balance"));guard(hex64(pre.at("creation_sha256")),"manual-read-preimage");
    for(const auto& k:{"runtime_sha256","library_sha256","reader_sha256"})guard(hex64(identity.at(k)) && identity.at(k)==reg.at(k),"manual-read-image-binding");
    for(const auto& k:{"run","boot","actor_hex","facts","runtime_sha256","library_sha256"})guard(writer.at(k)==reg.at(k),"manual-read-writer-binding");
    guard(writer.at("connection")==a.at("writer") && writer.at("case")==a.at("journal_case"),"manual-read-writer-binding");number(writer.at("pid"));number(writer.at("start"));
    ManualFinancialRequest request;request.run=a.at("run");request.case_id=a.at("case");request.journal_case=a.at("journal_case");request.phase=a.at("phase");request.writer=a.at("writer");if(a.at("previous")!="none")request.previous=manual_split(a.at("previous"),',');
    auto payments=manual_split(reg.at("payments"),',');guard(payments.size()==4,"manual-read-payments");auto users=manual_split(reg.at("users"),',');
    request.fixture={reg.at("account"),reg.at("subaccount"),reg.at("project"),reg.at("currency"),reg.at("method"),reg.at("module"),{{payments[0],payments[1],payments[2],payments[3]}},users,reg.at("facts")};
    std::string schema_names;for(const auto& t:manual_financial_tables()){if(!schema_names.empty())schema_names+=" ";schema_names+=t;}
    auto schema=parse(files.at("manual.schema.original").raw,"paymenter-manual-financial-schema-v1",schema_names);
    for(const auto& t:manual_financial_tables()){auto raw=files.at("manual.schema."+t+".original").raw;guard(hash(raw)==schema.at(t),"manual-read-schema-hash");request.schemas[t]=raw;}
    std::istringstream keys(files.at("manual.foreign_keys.original").raw);std::string line;guard(bool(std::getline(keys,line)) && line=="paymenter-manual-financial-foreign-keys-v1","manual-read-foreign-keys");while(std::getline(keys,line))request.foreign_keys.push_back(manual_split(line,'\t'));
    manual_financial_validate_request(request);
    guard(writer.at("payment")==payments[static_cast<size_t>(request.journal_case[1]-'1')],"manual-read-writer-payment");
    auto current=[&] {
        auto live=[&] {
            guard(getpid()==process && boot()==a.at("boot") && now_seconds()>=issued &&
                  now_seconds()<expires && Clock::now()<end,"manual-read-current");
            guard(!dir->exists("revoked.v1") && !dir->exists("retired.v1"),"manual-read-revoked");
        };
        live();
        guard(images()==files.at("manual.identity.original").raw,"manual-read-image-binding");
        auto fresh=dir->read_batch(names);
        for(size_t i=0;i<names.size();++i)
            guard(same_file(originals[i],fresh[i]),"manual-read-original-changed");
        // A revocation during the custody reads must also refuse the final step.
        live();
    };
    current();dir->create("manual.read.started.original","paymenter-manual-financial-read-started-v1\nrequest="+a.at("request")+"\napproval_sha256="+hash(approval.raw)+"\n");
    try {
        current();auto credential=credential_file(credentials,owner);auto password=credential_password(credential.raw);
        struct Wipe {std::string& text;~Wipe(){if(!text.empty())OPENSSL_cleanse(&text[0],text.size());}} wipe{password};
        auto channel_current=[&]{current();auto fresh=credential_file(credentials,owner);guard(same_file(credential,fresh),"manual-read-credential-changed");};
        ManualReadDatabase db(password,database,socket,channel_current);OPENSSL_cleanse(&password[0],password.size());password.clear();
        ManualFinancialRead reader(db,database,socket,end);auto started=now_seconds();auto result=reader.observe(request);channel_current();
        std::string raw="paymenter-manual-financial-read-original-v1\n";
        for(const auto& key:{"run","request","boot","actor_hex","case","journal_case","phase","facts","writer","registry_sha256","preimage_sha256","schema_sha256","foreign_keys_sha256","writer_sha256","identity_sha256"})raw+=std::string(key)+"="+a.at(key)+"\n";
        raw+="connection="+result.connection+"\nstarted="+std::to_string(started)+"\nfinished="+std::to_string(now_seconds())+"\nisolation=READ COMMITTED\nautocommit=1\ntransaction_open=0\napproval_sha256="+hash(approval.raw)+"\n";
        for(const auto& row:result.rows){raw+="row=";for(size_t i=0;i<row.size();++i){if(i)raw+='\t';raw+=row[i];}raw+='\n';}
        current();dir->create_manual_financial_observation(raw);channel_current();
        dir->create("manual.read.complete.original","paymenter-manual-financial-read-complete-v1\nrequest="+a.at("request")+"\noriginal_sha256="+hash(raw)+"\n");channel_current();
    } catch(...) {if(!dir->exists("manual.read.failure.original"))dir->create("manual.read.failure.original","paymenter-manual-financial-read-incomplete-v1\nrequest="+a.at("request")+"\n");throw;}
}
} }
#ifndef PAYMENTER_QA_MANUAL_READER_NO_MAIN
#if !defined(PAYMENTER_QA_MANUAL_CREDENTIAL_FILE) || !defined(PAYMENTER_QA_MANUAL_DATABASE) || !defined(PAYMENTER_QA_MANUAL_SOCKET) || !defined(PAYMENTER_QA_MANUAL_RUNTIME_PATH) || !defined(PAYMENTER_QA_MANUAL_LIBRARY_PATH)
#error "Reviewed fixed source credential/database/socket/runtime/library build configuration required"
#endif
int main(int argc,char**argv){using namespace paymenter_qa;try{
#ifdef __linux__
    guard(argc==2 && geteuid()==0 && getegid()==0,"manual-read-linux-root");char exe[4096];auto n=readlink("/proc/self/exe",exe,sizeof(exe));guard(n>0 && n<static_cast<ssize_t>(sizeof(exe)),"manual-read-executable");
    auto dir=std::make_shared<PrivateDirectory>(argv[1],Owner{0,0});struct statfs fs;guard(fstatfs(dir->descriptor(),&fs)==0 && (fs.f_type==0xEF53 || fs.f_type==0x58465342 || fs.f_type==0x01021994),"manual-read-filesystem");
    ManualReadImages images(PAYMENTER_QA_MANUAL_RUNTIME_PATH,PAYMENTER_QA_MANUAL_LIBRARY_PATH,std::string(exe,n),Owner{0,0});
    perform_manual_financial_read(dir,PAYMENTER_QA_MANUAL_CREDENTIAL_FILE,Owner{0,0},PAYMENTER_QA_MANUAL_DATABASE,PAYMENTER_QA_MANUAL_SOCKET,boot_id,[&]{return images.current();});std::cout<<"manual-financial-observation-retained\n";return 0;
#else
    (void)argc;(void)argv;throw ProbeError("manual-read-linux-root");
#endif
}catch(...){std::cerr<<"manual-financial-observation-incomplete\n";return 1;}}
#endif
