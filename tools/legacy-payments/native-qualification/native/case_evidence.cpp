// Source-private evidence control only. No SDK invocation, SQL or acceptance.
// Compile alone; the native control publisher/bridge are amalgamated below.
#define PAYMENTER_QA_ISSUER_NO_MAIN
#include "control_issuer.cpp"
#include <set>
namespace paymenter_qa {
namespace {
using RetiredChildCheck=std::function<bool(pid_t,const std::string&)>;
std::string evidence_identity(const File& file) {
    const auto& s=file.identity;
    return hash(std::to_string(s.dev)+":"+std::to_string(s.ino)+":"+std::to_string(s.size)+":"+
        std::to_string(s.mode)+":"+std::to_string(s.uid)+":"+std::to_string(s.gid)+":"+
        std::to_string(s.mtime)+":"+std::to_string(s.mnano)+":"+std::to_string(s.ctime)+":"+std::to_string(s.cnano));
}
struct CaseBinding {
    std::shared_ptr<NativeContext> context;
    File authority,issuance,intent,active_intent,writer;
    Fields fields,diagnostic;
    Clock::time_point case_deadline;
};
struct BoundRead {
    std::shared_ptr<PrivateDirectory> directory;
    File approval,started,original,complete;
    Fields fields;
    std::vector<std::string> rows;
};
class FrameworkCaseEvidence {
    const std::shared_ptr<PrivateDirectory> dir_;const Owner owner_;
    const std::function<std::string()> boot_;const ImageReader images_;
    const TargetCheck target_;const RetiredChildCheck gone_;
    const pid_t process_=getpid();
    void process()const {guard(getpid()==process_,"evidence-process");}
    CaseBinding binding(const std::string& id)const {
        process();(void)case_mode(id);
        auto context=std::make_shared<NativeContext>(dir_,boot_,images_);
        CaseBinding b{context,dir_->read("authority.v1",4096),dir_->read("issuance.native.original",4096),
            dir_->read("intent."+id+".original",4096),dir_->read("intent.v1",4096),dir_->read("writer."+id+".original",4096),{}, {}, {}};
        guard(b.authority.raw==context->authority() && b.issuance.raw==context->receipt(),"evidence-issued-authority");
        guard(b.intent.raw==b.active_intent.raw,"evidence-active-intent");
        b.fields=parse(b.intent.raw,"paymenter-source-qa-intent-v1","run case nonce facts pid start issued expires");
        b.diagnostic=parse(b.writer.raw,"paymenter-source-qa-writer-v1","run case nonce facts connection pid start intent_sha256");
        for(const auto& key:{"run","case","nonce","facts","pid","start"})
            guard(b.fields.at(key)==b.diagnostic.at(key),"evidence-writer-binding");
        guard(b.fields.at("run")==context->fields.at("run") && b.fields.at("case")==id &&
            b.fields.at("nonce")==context->fields.at("nonce_"+id) && b.fields.at("facts")==context->fields.at("facts_"+id) &&
            b.diagnostic.at("intent_sha256")==hash(b.intent.raw),"evidence-intent-binding");
        number(b.diagnostic.at("connection"));
        auto pid=number(b.fields.at("pid"));guard(pid>1 && pid<=INT_MAX,"evidence-writer-pid");
        auto issued=number(b.fields.at("issued")),expires=number(b.fields.at("expires"));
        guard(issued<expires && expires-issued<=30 && issued>=context->admitted.issued && expires<=context->admitted.expires,"evidence-case-window");
        auto now=now_seconds();guard(now>=issued && now<expires,"evidence-case-window");
        b.case_deadline=std::min(context->deadline,Clock::now()+std::chrono::seconds(expires-now));
        current(b);return b;
    }
    void current(const CaseBinding& b)const {
        process();b.context->check();
        guard(!dir_->exists("revoked.v1") && !dir_->exists("evidence.failure.original"),"evidence-held");
        for(const auto& p:{std::make_pair("authority.v1",&b.authority),std::make_pair("issuance.native.original",&b.issuance),
                          std::make_pair("intent.v1",&b.active_intent)})
            guard(same_file(*p.second,dir_->read(p.first,4096)),"evidence-original-changed");
        auto id=b.fields.at("case");
        guard(same_file(b.intent,dir_->read("intent."+id+".original",4096)) &&
            same_file(b.writer,dir_->read("writer."+id+".original",4096)),"evidence-original-changed");
        auto now=now_seconds();
        guard(now>=number(b.fields.at("issued")) && now<number(b.fields.at("expires")) &&
            Clock::now()<b.case_deadline && target_(static_cast<pid_t>(number(b.fields.at("pid"))),b.fields.at("start"),b.context->fields),"evidence-writer-current");
        guard(now_seconds()>=number(b.fields.at("issued")) && now_seconds()<number(b.fields.at("expires")) &&
            Clock::now()<b.case_deadline,"evidence-writer-current");
    }
    void read_current(const CaseBinding& b,const BoundRead& r)const {
        current(b);
        for(const auto& p:{std::make_pair("read.approval.native.original",&r.approval),std::make_pair("read.started.original",&r.started),
                          std::make_pair("read.original",&r.original),std::make_pair("read.complete.original",&r.complete)})
            guard(same_file(*p.second,r.directory->read(p.first,4096)),"evidence-read-changed");
        guard(!r.directory->exists("read.failure.original") && !r.directory->exists("revoked.v1") && !r.directory->exists("retired.v1"),"evidence-read-incomplete");
        current(b);
    }
    BoundRead read(const CaseBinding& b,const std::string& slot)const {
        guard(slot=="barrier" || slot=="after" || slot=="reconcile","evidence-slot");
        const auto id=b.fields.at("case");const std::string phase=slot=="barrier"?"barrier":"after";
        auto rd=std::make_shared<PrivateDirectory>(dir_->path()+"/read."+id+"."+slot,owner_);
        BoundRead r{rd,rd->read("read.approval.native.original",4096),rd->read("read.started.original",4096),
            rd->read("read.original",4096),rd->read("read.complete.original",4096),{}, {}};
        auto a=parse(r.approval.raw,"paymenter-source-qa-read-approval-native-v1","intent kind run request boot case phase facts writer previous issued expires");
        auto row_start=r.original.raw.find("\nrow=");
        const auto header=row_start==std::string::npos?r.original.raw:r.original.raw.substr(0,row_start+1);
        r.fields=parse(header,"paymenter-source-qa-read-original-native-v1","kind run request boot case phase facts writer connection started finished isolation autocommit transaction_open approval_sha256");
        guard(a.at("intent")=="observe-framework" && a.at("kind")=="observation" && a.at("phase")==phase && hex64(a.at("request")),"evidence-read-purpose");
        for(const auto& key:{"kind","run","request","boot","case","phase","facts","writer"})
            guard(a.at(key)==r.fields.at(key),"evidence-read-binding");
        guard(a.at("run")==b.fields.at("run") && a.at("case")==id && a.at("facts")==b.fields.at("facts") &&
            a.at("boot")==b.context->fields.at("boot") && a.at("writer")==b.diagnostic.at("connection"),"evidence-read-writer");
        const auto connection=r.fields.at("connection");number(connection);
        guard(connection!=a.at("writer") && r.fields.at("isolation")=="READ COMMITTED" && r.fields.at("autocommit")=="1" &&
            r.fields.at("transaction_open")=="0" && r.fields.at("approval_sha256")==hash(r.approval.raw),"evidence-independent-session");
        if(a.at("previous")!="none") {
            guard(!a.at("previous").empty() && a.at("previous").back()!=',',"evidence-read-history");
            std::istringstream in(a.at("previous"));std::string value;std::set<std::string> history;
            while(std::getline(in,value,',')){number(value);guard(value!=connection && history.insert(value).second && history.size()<=64,"evidence-read-history");}
        }
        auto issued=number(a.at("issued")),expires=number(a.at("expires")),started=number(r.fields.at("started")),finished=number(r.fields.at("finished"));
        guard(issued<expires && expires-issued<=30 && issued>=number(b.fields.at("issued")) && expires<=number(b.fields.at("expires")) &&
            issued<=started && started<=finished && finished<=now_seconds() && finished<expires,"evidence-read-window");
        auto s=parse(r.started.raw,"paymenter-source-qa-read-started-native-v1","request approval_sha256");
        auto c=parse(r.complete.raw,"paymenter-source-qa-read-complete-native-v1","request original_sha256");
        guard(s.at("request")==a.at("request") && c.at("request")==a.at("request") && s.at("approval_sha256")==hash(r.approval.raw) &&
            c.at("original_sha256")==hash(r.original.raw),"evidence-read-original");
        if(row_start!=std::string::npos) {
            std::istringstream rows(r.original.raw.substr(row_start+1));std::string line;
            while(std::getline(rows,line)){guard(line.compare(0,4,"row=")==0 && r.rows.size()<2,"evidence-rows");r.rows.push_back(line.substr(4));}
            std::sort(r.rows.begin(),r.rows.end());
        }
        std::vector<std::string> expected;
        if(phase=="barrier")guard(id=="F4","evidence-barrier-case");
        else if(id=="F1" || id=="F4")expected={"business,"+b.fields.at("run")+","+id+",0.0100,"+b.fields.at("facts"),
            "journal,"+b.fields.at("run")+","+id+","+id+","+b.fields.at("facts")};
        guard(r.rows==expected,"evidence-case-outcome");read_current(b,r);return r;
    }
    std::string observed(const CaseBinding& b,const BoundRead& r)const {
        return "paymenter-source-qa-bound-observation-v1\nrun="+b.fields.at("run")+"\ncase="+b.fields.at("case")+"\nphase="+r.fields.at("phase")+
            "\nnonce="+b.fields.at("nonce")+"\nfacts="+b.fields.at("facts")+"\nwriter_sha256="+hash(b.writer.raw)+"\nintent_sha256="+hash(b.intent.raw)+
            "\nauthority_sha256="+hash(b.authority.raw)+"\nread_sha256="+hash(r.original.raw)+"\napproval_sha256="+hash(r.approval.raw)+
            "\ncompletion_sha256="+hash(r.complete.raw)+"\nreader="+r.fields.at("connection")+"\nrequest="+r.fields.at("request")+
            "\nwriter_identity="+evidence_identity(b.writer)+"\nintent_identity="+evidence_identity(b.intent)+
            "\nauthority_identity="+evidence_identity(b.authority)+"\nread_identity="+evidence_identity(r.original)+
            "\napproval_identity="+evidence_identity(r.approval)+"\nstarted_identity="+evidence_identity(r.started)+
            "\ncompletion_identity="+evidence_identity(r.complete)+"\n";
    }
    void publish(const std::string& name,const std::string& raw,const std::function<void()>& check)const {
        guard(!dir_->exists(name),"evidence-reused-result");check();
        try{dir_->create(name,raw);check();}
        catch(...){try{if(!dir_->exists("evidence.failure.original"))dir_->create("evidence.failure.original","paymenter-source-qa-evidence-incomplete-v1\n");}catch(...){}throw;}
    }
public:
    FrameworkCaseEvidence(std::shared_ptr<PrivateDirectory> dir,Owner owner,std::function<std::string()> boot,ImageReader images,
                          TargetCheck target,RetiredChildCheck gone):dir_(dir),owner_(owner),boot_(boot),images_(images),target_(target),gone_(gone){}
    void bind(const std::string& id,const std::string& phase)const {
        guard(phase=="after" || phase=="barrier","evidence-phase");auto b=binding(id);auto r=read(b,phase);
        publish("observed."+id+"."+phase+".original",observed(b,r),[&]{read_current(b,r);});
    }
    void withhold(const std::string& id)const {
        auto b=binding(id);guard(id=="F1" || id=="F4","evidence-withhold-case");auto r=read(b,"after");
        auto sealed=dir_->read("observed."+id+".after.original",4096);guard(sealed.raw==observed(b,r),"evidence-unsealed-read");
        auto caller=dir_->read("caller."+id+".original",4096),reply=dir_->read("caller."+id+".stdout",16384);
        auto c=parse(caller.raw,"paymenter-source-qa-caller-capture-v1","run case boot pid start exit stdout_eof stderr_eof stdout_sha256 stderr_bytes delivery nonce facts intent_sha256");
        auto pid=number(c.at("pid"));guard(pid>1 && pid<=INT_MAX && pid!=number(b.fields.at("pid")),"evidence-caller");number(c.at("start"));
        guard(c.at("run")==b.fields.at("run") && c.at("case")==id && c.at("boot")==b.context->fields.at("boot") &&
            c.at("exit")=="0" && c.at("stdout_eof")=="1" && c.at("stderr_eof")=="1" && c.at("stderr_bytes")=="0" &&
            c.at("delivery")=="withheld" && c.at("stdout_sha256")==hash(reply.raw) && c.at("nonce")==b.fields.at("nonce") &&
            c.at("facts")==b.fields.at("facts") && c.at("intent_sha256")==hash(b.intent.raw),"evidence-caller-incomplete");
        auto check=[&] {
            read_current(b,r);
            guard(same_file(sealed,dir_->read("observed."+id+".after.original",4096)) && same_file(caller,dir_->read("caller."+id+".original",4096)) &&
                same_file(reply,dir_->read("caller."+id+".stdout",16384)) && gone_(static_cast<pid_t>(pid),c.at("start")),"evidence-caller-held");
        };
        publish("withheld."+id+".original","paymenter-source-qa-withheld-v1\nrun="+b.fields.at("run")+"\ncase="+id+"\nobservation_sha256="+
            hash(sealed.raw)+"\ncaller_sha256="+hash(caller.raw)+"\nreply_sha256="+hash(reply.raw)+
            "\nobservation_identity="+evidence_identity(sealed)+"\ncaller_identity="+evidence_identity(caller)+"\nreply_identity="+evidence_identity(reply)+"\n",check);
    }
    void recover(const std::string& id)const {
        auto b=binding(id);guard(id=="F1" || id=="F4","evidence-recovery-case");auto first=read(b,"after"),fresh=read(b,"reconcile");
        auto sealed=dir_->read("observed."+id+".after.original",4096);guard(sealed.raw==observed(b,first),"evidence-unsealed-read");
        auto withheld=dir_->read("withheld."+id+".original",4096),caller=dir_->read("caller."+id+".original",4096),reply=dir_->read("caller."+id+".stdout",16384);
        auto w=parse(withheld.raw,"paymenter-source-qa-withheld-v1","run case observation_sha256 caller_sha256 reply_sha256 observation_identity caller_identity reply_identity");
        guard(w.at("run")==b.fields.at("run") && w.at("case")==id && w.at("observation_sha256")==hash(sealed.raw) &&
            w.at("caller_sha256")==hash(caller.raw) && w.at("reply_sha256")==hash(reply.raw),"evidence-withheld-original");
        guard(w.at("observation_identity")==evidence_identity(sealed) && w.at("caller_identity")==evidence_identity(caller) &&
            w.at("reply_identity")==evidence_identity(reply),"evidence-withheld-custody");
        guard(std::make_pair(fresh.started.identity.ctime,fresh.started.identity.cnano)>=
            std::make_pair(withheld.identity.ctime,withheld.identity.cnano),"evidence-recovery-predates-loss");
        guard(first.fields.at("connection")!=fresh.fields.at("connection") && first.fields.at("request")!=fresh.fields.at("request") &&
            number(fresh.fields.at("started"))>=number(first.fields.at("finished")) && fresh.rows==first.rows,"evidence-recovery-mismatch");
        auto check=[&] {
            read_current(b,first);read_current(b,fresh);
            guard(same_file(withheld,dir_->read("withheld."+id+".original",4096)) && same_file(caller,dir_->read("caller."+id+".original",4096)) &&
                same_file(reply,dir_->read("caller."+id+".stdout",16384)),"evidence-recovery-changed");
        };
        publish("recovered."+id+".original","paymenter-source-qa-recovery-v1\nrun="+b.fields.at("run")+"\ncase="+id+
            "\nscope=collector-response-only\nwithheld_sha256="+hash(withheld.raw)+"\nfirst_sha256="+hash(first.original.raw)+"\nfresh_sha256="+hash(fresh.original.raw)+"\n",check);
    }
};
#ifdef __linux__
bool native_child_gone(pid_t pid,const std::string& start) {
    // Read-only lookup. ENOENT or a different kernel start means that exact
    // process is gone; permission/parse/I/O uncertainty must not grant retirement.
    const std::string path="/proc/"+std::to_string(pid)+"/stat";
    int fd=open(path.c_str(),O_RDONLY|O_CLOEXEC|O_NONBLOCK);
    if(fd<0){if(errno==ENOENT)return true;throw ProbeError("evidence-caller-lookup");}
    Fd holder(fd);auto raw=bounded(holder.fd,8192);auto end=raw.rfind(')');guard(end!=std::string::npos,"evidence-caller-stat");
    guard(raw.substr(0,raw.find(' '))==std::to_string(pid),"evidence-caller-stat");
    std::istringstream fields(raw.substr(end+1));std::string value;
    for(int i=0;i<20;++i)guard(bool(fields>>value),"evidence-caller-stat");
    number(value);return value!=start;
}
#endif
}
}
#ifndef PAYMENTER_QA_EVIDENCE_NO_MAIN
int main(int argc,char**argv) {
    using namespace paymenter_qa;
    try {
#ifdef __linux__
        guard(argc==4 && geteuid()==0 && getegid()==0,"evidence-linux-root-required");
        auto dir=std::make_shared<PrivateDirectory>(argv[1],Owner{0,0});
        struct statfs fs;guard(fstatfs(dir->descriptor(),&fs)==0 &&
            (fs.f_type==0xEF53 || fs.f_type==0x58465342 || fs.f_type==0x01021994),"evidence-filesystem");
        FrameworkCaseEvidence e(dir,Owner{0,0},boot_id,[](const std::string& path){return image(path,true);},native_target,native_child_gone);
        std::string id=argv[2],mode=argv[3];
        if(mode=="after" || mode=="barrier")e.bind(id,mode);
        else if(mode=="withhold")e.withhold(id);
        else if(mode=="recover")e.recover(id);
        else throw ProbeError("evidence-mode");
        std::cout<<"native-case-evidence-retained\n";return 0;
#else
        (void)argc;(void)argv;throw ProbeError("evidence-linux-root-required");
#endif
    }catch(const ProbeError& e){std::cerr<<e.what()<<'\n';}
    catch(...){std::cerr<<"evidence-failed\n";}
    return 1;
}
#endif
