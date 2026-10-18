// Standalone resource lifecycle, never an SDK component or financial issuer.
#define PAYMENTER_QA_ISSUER_NO_MAIN
#include "control_issuer.cpp"
#include "qa_retirement_mysql.cpp"
#include "credential_channel.hpp"
#include "resource_schema.hpp"
#include <openssl/crypto.h>
#include "resource_creation_mysql.hpp"
namespace paymenter_qa { namespace {
const char* const CREATE_FIELDS="intent run boot issued expires authority_approval_sha256 registry_sha256 build_sha256 manager_pid manager_start ddl_sha256 issuer_path issuer_sha256";
const char* const CREATED_FIELDS="run boot issued connection manager_pid manager_start authority_approval_sha256 authority_approval_identity registry_sha256 registry_identity build_sha256 build_identity create_approval_sha256 create_approval_identity started_sha256 started_identity absent_sha256 absent_identity business_sha256 business_identity journal_sha256 journal_identity module_dev module_ino issuer_pid issuer_start";
const char* const CLEANUP_FIELDS="intent run boot issued expires created_sha256 created_identity revoked_sha256 revoked_identity manager_pid manager_start complete_sha256 complete_identity";
std::string resource_identity(const File& f) {
    const auto& s=f.identity;
    return hash(std::to_string(s.dev)+":"+std::to_string(s.ino)+":"+std::to_string(s.size)+":"+std::to_string(s.mode)+":"+
        std::to_string(s.uid)+":"+std::to_string(s.gid)+":"+std::to_string(s.mtime)+":"+std::to_string(s.mnano)+":"+std::to_string(s.ctime)+":"+std::to_string(s.cnano));
}
std::string resource_ddl_hash(){auto ddl=qa_resource_ddl();return hash(ddl[0]+"\n"+ddl[1]);}
using ResourceRuntimeCheck=std::function<bool(pid_t,const std::string&,const Fields&,uint64_t,uint64_t)>;
using ResourceRetiredCheck=std::function<bool(pid_t,const std::string&)>;
struct ResourceHooks {
    Owner owner;std::function<std::string()> boot;ImageReader images;
    ResourceRuntimeCheck runtime;ResourceRetiredCheck retired;
    std::string issuer_path;std::function<std::string()> self_start;
};
void resource_pid(const std::string& s){auto n=number(s);guard(n>1 && n<=INT_MAX,"resource-pid");}
class ResourceCreationGuard {
    const std::shared_ptr<PrivateDirectory> dir_;const ResourceHooks hooks_;
    const pid_t process_=getpid();const std::thread::id thread_=std::this_thread::get_id();
    const File approval_,issuer_;const std::string self_start_;
    const std::shared_ptr<NativeContext> context_;
    const Fields fields_;Clock::time_point end_;
public:
    ResourceCreationGuard(std::shared_ptr<PrivateDirectory> dir,const ResourceHooks& hooks):
        dir_(dir),hooks_(hooks),approval_(dir->read("qa.create.approval.original",4096)),issuer_(hooks.images(hooks.issuer_path)),self_start_(hooks.self_start()),
        context_(std::make_shared<NativeContext>(dir,hooks.boot,hooks.images)),fields_(parse(approval_.raw,"paymenter-source-qa-create-approval-v1",CREATE_FIELDS)) {
        const auto& f=fields_;auto issued=number(f.at("issued")),expires=number(f.at("expires")),now=now_seconds();
        guard(f.at("intent")=="create-owned-qa" && f.at("run")==context_->fields.at("run") && f.at("boot")==context_->fields.at("boot") &&
            f.at("authority_approval_sha256")==hash(context_->approval.raw) && f.at("registry_sha256")==hash(context_->registry.raw) &&
            f.at("build_sha256")==hash(context_->build.raw) && f.at("ddl_sha256")==resource_ddl_hash(),"resource-create-approval");
        guard(f.at("issuer_path")==hooks_.issuer_path && f.at("issuer_sha256")==hash(issuer_.raw) && issuer_.raw.compare(0,4,"\177ELF")==0,"resource-issuer");
        resource_pid(f.at("manager_pid"));number(f.at("manager_start"));number(self_start_);
        guard(issued<expires && expires-issued<=30 && issued>=context_->admitted.issued && expires<=context_->admitted.expires && now>=issued && now<expires,"resource-create-expiry");
        end_=std::min(context_->deadline,Clock::now()+std::chrono::seconds(expires-now));current();
    }
    void current()const {
        guard(getpid()==process_ && std::this_thread::get_id()==thread_,"resource-create-process");context_->check();
        guard(Clock::now()<end_ && now_seconds()>=number(fields_.at("issued")) && now_seconds()<number(fields_.at("expires")),"resource-create-expiry");
        guard(same_file(approval_,dir_->read("qa.create.approval.original",4096)) && same_file(issuer_,hooks_.images(hooks_.issuer_path)) && hooks_.self_start()==self_start_,"resource-create-changed");
        for(const auto& name:{"authority.v1","intent.v1","revoked.v1","retired.v1","qa.resources.failure.original"})guard(!dir_->exists(name),"resource-create-active");
        for(const auto& id:{"F1","F2","F3","F4","F5"})guard(!dir_->exists(std::string("intent.")+id+".original") && !dir_->exists(std::string("writer.")+id+".original"),"resource-create-active");
        guard(hooks_.boot()==fields_.at("boot") && hooks_.runtime(static_cast<pid_t>(number(fields_.at("manager_pid"))),fields_.at("manager_start"),
            context_->fields,context_->module.identity.dev,context_->module.identity.ino),"resource-create-manager");
        guard(Clock::now()<end_ && now_seconds()<number(fields_.at("expires")),"resource-create-expiry");
    }
    const Fields& fields()const{return fields_;}
    const NativeContext& context()const{return *context_;}
    const File& approval()const{return approval_;}
    const std::string& start()const{return self_start_;}
};
class OwnedQaResources {
    const std::shared_ptr<PrivateDirectory> dir_;const pid_t process_=getpid();
    std::map<std::string,File> files_;
    File file(const char* name)const{return files_.at(name);}
public:
    Fields created,create_approval,authority;
    QaRetirementRegistry registry;
    explicit OwnedQaResources(std::shared_ptr<PrivateDirectory> dir):dir_(dir) {
        guard(!dir_->exists("qa.resources.failure.original"),"resource-creation-incomplete");
        for(const auto& name:{"approval.native.original","registry.original","build.original","qa.create.approval.original","qa.resources.started.original",
                             "qa.resources.absent.original","qa.schema.business.original","qa.schema.journal.original","qa.resources.created.original","qa.resources.complete.original"})
            files_.emplace(name,dir_->read(name,4096));
        created=parse(file("qa.resources.created.original").raw,"paymenter-source-qa-created-v1",CREATED_FIELDS);
        create_approval=parse(file("qa.create.approval.original").raw,"paymenter-source-qa-create-approval-v1",CREATE_FIELDS);
        auto lease_=lease(NativeContext::execution(file("approval.native.original")));authority=lease_.fields;
        auto r=parse(file("registry.original").raw,"paymenter-source-qa-registry-native-v1","run tables objects diagnostics");
        guard(r.at("run")==created.at("run") && r.at("tables")=="paymenter_qa_business_v1,paymenter_qa_journal_v1" && r.at("objects")=="none" && r.at("diagnostics")==REGISTRY_DIAGNOSTICS,"resource-original-registry");
        auto b=parse(file("build.original").raw,"paymenter-source-qa-build-native-v1","run module_path module_sha256 runtime_path runtime_sha256");
        guard(b.at("run")==created.at("run"),"resource-original-build");
        for(const auto& key:{"module_path","module_sha256","runtime_path","runtime_sha256"})guard(b.at(key)==authority.at(key),"resource-original-build");
        for(const auto& key:{"run","boot","manager_pid","manager_start"})guard(create_approval.at(key)==created.at(key),"resource-created-binding");
        guard(created.at("run")==authority.at("run") && created.at("boot")==authority.at("boot") && reader_connection(created.at("connection")) &&
            create_approval.at("intent")=="create-owned-qa" && create_approval.at("ddl_sha256")==resource_ddl_hash(),"resource-created-binding");
        resource_pid(created.at("manager_pid"));number(created.at("manager_start"));resource_pid(created.at("issuer_pid"));number(created.at("issuer_start"));number(created.at("module_dev"));number(created.at("module_ino"));
        auto issued=number(create_approval.at("issued")),expires=number(create_approval.at("expires")),when=number(created.at("issued"));
        guard(issued<expires && expires-issued<=30 && issued>=lease_.issued && expires<=lease_.expires && when>=issued && when<expires,"resource-created-time");
        for(const auto& spec:std::vector<std::array<std::string,2>>{
            {{"authority_approval","approval.native.original"}},{{"registry","registry.original"}},{{"build","build.original"}},{{"create_approval","qa.create.approval.original"}},
            {{"started","qa.resources.started.original"}},{{"absent","qa.resources.absent.original"}},{{"business","qa.schema.business.original"}},{{"journal","qa.schema.journal.original"}}}){
            const auto f=files_.at(spec[1]);guard(created.at(spec[0]+"_sha256")==hash(f.raw) && created.at(spec[0]+"_identity")==resource_identity(f),"resource-created-custody");}
        for(const auto& key:{"authority_approval_sha256","registry_sha256","build_sha256"})guard(create_approval.at(key)==created.at(key),"resource-created-approval");
        guard(authority.at("registry_sha256")==created.at("registry_sha256") && authority.at("build_sha256")==created.at("build_sha256"),"resource-created-authority");
        auto started=parse(file("qa.resources.started.original").raw,"paymenter-source-qa-resource-started-v1","run create_approval_sha256 create_approval_identity");
        guard(started.at("run")==created.at("run") && started.at("create_approval_sha256")==created.at("create_approval_sha256") && started.at("create_approval_identity")==created.at("create_approval_identity"),"resource-started");
        auto absent=parse(file("qa.resources.absent.original").raw,"paymenter-source-qa-resource-absence-v1","run connection started_sha256 ddl_sha256 result");
        guard(absent.at("run")==created.at("run") && absent.at("connection")==created.at("connection") && absent.at("started_sha256")==created.at("started_sha256") &&
            absent.at("ddl_sha256")==resource_ddl_hash() && absent.at("result")=="both-absent","resource-absence");
        auto complete=parse(file("qa.resources.complete.original").raw,"paymenter-source-qa-resource-complete-v1","run created_sha256 created_identity");
        guard(complete.at("run")==created.at("run") && complete.at("created_sha256")==hash(file("qa.resources.created.original").raw) &&
            complete.at("created_identity")==resource_identity(file("qa.resources.created.original")),"resource-completion");
        registry.run=created.at("run");registry.definitions={{file("qa.schema.business.original").raw,file("qa.schema.journal.original").raw}};
        for(const auto& id:{"F1","F2","F3","F4"})registry.cases.push_back({id,authority.at(std::string("facts_")+id)});
        current();
    }
    void current()const {
        guard(getpid()==process_,"resource-owned-process");dir_->unchanged();guard(!dir_->exists("qa.resources.failure.original"),"resource-creation-incomplete");
        std::vector<std::pair<std::string,size_t>> names;for(const auto& f:files_)names.push_back({f.first,4096});
        auto originals=dir_->read_batch(names);size_t index=0;
        for(const auto& f:files_)guard(same_file(f.second,originals[index++]),"resource-original-replaced");
    }
    File created_original()const{current();return file("qa.resources.created.original");}
    File complete_original()const{current();return file("qa.resources.complete.original");}
};
class NativeQaResourceRetirementGuard:public QaRetirementGuard {
    const std::shared_ptr<PrivateDirectory> dir_;const ResourceHooks hooks_;const pid_t process_=getpid();
    const std::thread::id thread_=std::this_thread::get_id();
    const OwnedQaResources resources_;const File approval_,revoked_,issuer_;
    const Fields fields_;const std::string self_start_;
    Clock::time_point end_;std::map<std::string,File> writers_;std::unique_ptr<File> started_;
    std::vector<std::pair<pid_t,std::string>> targets_;
public:
    NativeQaResourceRetirementGuard(std::shared_ptr<PrivateDirectory> dir,const ResourceHooks& hooks):
        dir_(dir),hooks_(hooks),resources_(dir),approval_(dir->read("qa.cleanup.approval.original",4096)),revoked_(dir->read("revoked.v1",4096)),
        issuer_(hooks.images(hooks.issuer_path)),fields_(parse(approval_.raw,"paymenter-source-qa-cleanup-approval-v1",CLEANUP_FIELDS)),self_start_(hooks.self_start()) {
        auto created=resources_.created_original(),complete=resources_.complete_original();const auto& f=fields_;const auto& c=resources_.created;
        guard(f.at("intent")=="retire-owned-qa" && f.at("run")==c.at("run") && f.at("boot")==c.at("boot") && f.at("created_sha256")==hash(created.raw) &&
            f.at("created_identity")==resource_identity(created) && f.at("revoked_sha256")==hash(revoked_.raw) && f.at("revoked_identity")==resource_identity(revoked_) && f.at("complete_sha256")==hash(complete.raw) && f.at("complete_identity")==resource_identity(complete),"resource-cleanup-approval");
        guard(resources_.create_approval.at("issuer_path")==hooks.issuer_path && resources_.create_approval.at("issuer_sha256")==hash(issuer_.raw),"resource-cleanup-issuer");
        resource_pid(f.at("manager_pid"));number(f.at("manager_start"));number(self_start_);
        auto issued=number(f.at("issued")),expires=number(f.at("expires")),now=now_seconds();
        guard(issued<expires && expires-issued<=30 && now>=issued && now<expires,"resource-cleanup-expiry");end_=Clock::now()+std::chrono::seconds(expires-now);
        targets_.push_back({static_cast<pid_t>(number(c.at("manager_pid"))),c.at("manager_start")});
        for(const auto& id:{"F1","F2","F3","F4","F5"}) {
            const std::string intent=std::string("intent.")+id+".original",writer=std::string("writer.")+id+".original";
            guard(std::string(id)!="F5" || (!dir->exists(intent) && !dir->exists(writer)),"resource-unknown-writer");
            guard(!dir->exists(writer) || dir->exists(intent),"resource-writer-without-intent");
            if(!dir->exists(intent))continue;
            auto original=dir->read(intent,4096);auto f_=parse(original.raw,"paymenter-source-qa-intent-v1","run case nonce facts pid start issued expires");
            guard(f_.at("run")==c.at("run") && f_.at("case")==id && f_.at("nonce")==resources_.authority.at(std::string("nonce_")+id) &&
                f_.at("facts")==resources_.authority.at(std::string("facts_")+id),"resource-writer-intent");
            resource_pid(f_.at("pid"));number(f_.at("start"));
            auto a=number(f_.at("issued")),b=number(f_.at("expires"));guard(a<b && b-a<=30 && a>=number(resources_.authority.at("issued")) && b<=number(resources_.authority.at("expires")),"resource-writer-window");
            writers_.emplace(intent,original);targets_.push_back({static_cast<pid_t>(number(f_.at("pid"))),f_.at("start")});
            if(dir->exists(writer)) {
                auto w=dir->read(writer,4096);auto d=parse(w.raw,"paymenter-source-qa-writer-v1","run case nonce facts connection pid start intent_sha256");
                for(const auto& key:{"run","case","nonce","facts","pid","start"})guard(d.at(key)==f_.at(key),"resource-writer-binding");
                guard(reader_connection(d.at("connection")) && d.at("intent_sha256")==hash(original.raw),"resource-writer-binding");writers_.emplace(writer,w);
            }
        }
        if(dir->exists("intent.v1")){auto active=dir->read("intent.v1",4096);bool matched=false;for(const auto& w:writers_)if(w.first.compare(0,7,"intent.")==0 && w.second.raw==active.raw)matched=true;
            guard(matched,"resource-unknown-active-intent");writers_.emplace("intent.v1",active);}
        preflight();
    }
    void preflight()const {
        guard(getpid()==process_ && std::this_thread::get_id()==thread_,"resource-cleanup-process");resources_.current();
        guard(now_seconds()>=number(fields_.at("issued")) && now_seconds()<number(fields_.at("expires")) && Clock::now()<end_,"resource-cleanup-expiry");
        guard(hooks_.boot()==fields_.at("boot") && hooks_.self_start()==self_start_ && same_file(issuer_,hooks_.images(hooks_.issuer_path)),"resource-cleanup-runtime");
        guard(same_file(approval_,dir_->read("qa.cleanup.approval.original",4096)) && same_file(revoked_,dir_->read("revoked.v1",4096)),"resource-cleanup-changed");
        guard(!dir_->exists("qa.cleanup.failure.original") && !dir_->exists("retired.v1"),"resource-cleanup-incomplete");
        if(started_)guard(same_file(*started_,dir_->read("qa.cleanup.started.original",4096)),"resource-cleanup-started-changed");
        for(const auto& w:writers_)guard(same_file(w.second,dir_->read(w.first,4096)),"resource-writer-replaced");
        for(const auto& id:{"F1","F2","F3","F4","F5"})for(const auto& prefix:{"writer.","intent."}) {
            const std::string name=std::string(prefix)+id+".original";guard(dir_->exists(name)==bool(writers_.count(name)),"resource-new-writer");}
        guard(dir_->exists("intent.v1")==bool(writers_.count("intent.v1")),"resource-new-writer");
        for(const auto& target:targets_)guard(hooks_.retired(target.first,target.second),"resource-writer-not-retired");
        guard(hooks_.runtime(static_cast<pid_t>(number(fields_.at("manager_pid"))),fields_.at("manager_start"),resources_.authority,
            number(resources_.created.at("module_dev")),number(resources_.created.at("module_ino"))),"resource-replacement-manager");
        guard(Clock::now()<end_ && now_seconds()<number(fields_.at("expires")),"resource-cleanup-expiry");
    }
    void bind_started(const File& started) {
        preflight();guard(!started_,"resource-cleanup-started-reuse");
        auto fields=parse(started.raw,"paymenter-source-qa-cleanup-started-v1","run approval_sha256 issuer_pid issuer_start");
        guard(fields.at("run")==fields_.at("run") && fields.at("approval_sha256")==hash(approval_.raw) && fields.at("issuer_pid")==std::to_string(process_) &&
            fields.at("issuer_start")==self_start_ && same_file(started,dir_->read("qa.cleanup.started.original",4096)),"resource-cleanup-started");
        started_.reset(new File(started));current();
    }
    void current()const override {preflight();guard(bool(started_),"resource-cleanup-not-started");}
    std::string approval_hash()const{preflight();return hash(approval_.raw);}
    const QaRetirementRegistry& registry()const{return resources_.registry;}
    Clock::time_point deadline()const{return end_;}
    const Fields& fields()const{return fields_;}
};
class NativeQaResourceFactory {
    const std::shared_ptr<PrivateDirectory> dir_;const ResourceHooks hooks_;
    const pid_t process_=getpid();const std::thread::id thread_=std::this_thread::get_id();
    void process()const{guard(getpid()==process_ && std::this_thread::get_id()==thread_,"resource-factory-process");}
    void failure(const char* name,const std::string& run)const{try{if(!dir_->exists(name))dir_->create(name,"paymenter-source-qa-resource-incomplete-v1\nrun="+run+"\n");}catch(...){} }
public:
    NativeQaResourceFactory(std::shared_ptr<PrivateDirectory> dir,const ResourceHooks& hooks):dir_(dir),hooks_(hooks){}
    void create(const std::string& credential,const std::string& database,const std::string& socket)const {
        process();ResourceCreationGuard g(dir_,hooks_);
        for(const auto& name:{"qa.resources.started.original","qa.resources.absent.original","qa.schema.business.original","qa.schema.journal.original","qa.resources.created.original","qa.resources.complete.original","qa.resources.failure.original"})guard(!dir_->exists(name),"resource-create-reuse");
        const auto run=g.fields().at("run");
        dir_->create("qa.resources.started.original","paymenter-source-qa-resource-started-v1\nrun="+run+"\ncreate_approval_sha256="+hash(g.approval().raw)+"\ncreate_approval_identity="+resource_identity(g.approval())+"\n");
        auto started=dir_->read("qa.resources.started.original",4096);
        std::map<std::string,File> retained{{"qa.resources.started.original",started},{"approval.native.original",g.context().approval},
            {"registry.original",g.context().registry},{"build.original",g.context().build},{"qa.create.approval.original",g.approval()}};
        try {
            auto current=[&]{g.current();for(const auto& f:retained)guard(same_file(f.second,dir_->read(f.first,4096)),"resource-retained-changed");};
            auto config=credential_file(credential,hooks_.owner);auto password=credential_password(config.raw);current();
            NativeQaCreationDatabase db(password,database,socket,current);OPENSSL_cleanse(&password[0],password.size());password.clear();
            current();guard(same_file(config,credential_file(credential,hooks_.owner)),"resource-credential-changed");db.require_absent();
            dir_->create("qa.resources.absent.original","paymenter-source-qa-resource-absence-v1\nrun="+run+"\nconnection="+db.connection()+"\nstarted_sha256="+hash(started.raw)+"\nddl_sha256="+resource_ddl_hash()+"\nresult=both-absent\n");
            auto absent=dir_->read("qa.resources.absent.original",4096);retained.emplace("qa.resources.absent.original",absent);current();db.create(0);db.create(1);auto definitions=db.definitions();
            dir_->create("qa.schema.business.original",definitions[0]);dir_->create("qa.schema.journal.original",definitions[1]);
            auto business=dir_->read("qa.schema.business.original",4096),journal=dir_->read("qa.schema.journal.original",4096);
            guard(business.raw==definitions[0] && journal.raw==definitions[1],"resource-schema-archive");
            retained.emplace("qa.schema.business.original",business);retained.emplace("qa.schema.journal.original",journal);current();
            guard(same_file(absent,dir_->read("qa.resources.absent.original",4096)) && same_file(config,credential_file(credential,hooks_.owner)),"resource-creation-changed");
            guard(db.definitions()==definitions,"resource-schema-changed");
            Fields values{{"run",run},{"boot",g.fields().at("boot")},{"issued",std::to_string(now_seconds())},{"connection",db.connection()},
                {"manager_pid",g.fields().at("manager_pid")},{"manager_start",g.fields().at("manager_start")},
                {"module_dev",std::to_string(g.context().module.identity.dev)},{"module_ino",std::to_string(g.context().module.identity.ino)},
                {"issuer_pid",std::to_string(process_)},{"issuer_start",g.start()}};
            for(const auto& spec:std::vector<std::array<std::string,2>>{{{"authority_approval","approval.native.original"}},{{"registry","registry.original"}},{{"build","build.original"}},{{"create_approval","qa.create.approval.original"}},{{"started","qa.resources.started.original"}},{{"absent","qa.resources.absent.original"}},{{"business","qa.schema.business.original"}},{{"journal","qa.schema.journal.original"}}}){
                auto f=retained.at(spec[1]);values[spec[0]+"_sha256"]=hash(f.raw);values[spec[0]+"_identity"]=resource_identity(f);}
            current();dir_->create("qa.resources.created.original",encode_fields("paymenter-source-qa-created-v1",values,CREATED_FIELDS));auto created=dir_->read("qa.resources.created.original",4096);
            current();dir_->create("qa.resources.complete.original","paymenter-source-qa-resource-complete-v1\nrun="+run+"\ncreated_sha256="+hash(created.raw)+"\ncreated_identity="+resource_identity(created)+"\n");
            OwnedQaResources owned(dir_);owned.current();current();
        }catch(...){failure("qa.resources.failure.original",run);throw;}
    }
    QaRetirementResult retire(const std::string& credential,const std::string& database,const std::string& socket)const {
        process();NativeQaResourceRetirementGuard g(dir_,hooks_);g.preflight();
        for(const auto& name:{"qa.cleanup.started.original","qa.cleanup.complete.original","qa.cleanup.failure.original","qa.retirement.original"})guard(!dir_->exists(name),"resource-cleanup-reuse");
        const auto run=g.fields().at("run");dir_->create("qa.cleanup.started.original","paymenter-source-qa-cleanup-started-v1\nrun="+run+"\napproval_sha256="+g.approval_hash()+"\nissuer_pid="+std::to_string(process_)+"\nissuer_start="+hooks_.self_start()+"\n");auto started=dir_->read("qa.cleanup.started.original",4096);g.bind_started(started);
        try {
            auto config=credential_file(credential,hooks_.owner);auto password=credential_password(config.raw);g.current();
            NativeQaRetirementDatabase db(password,database,socket,g,g.registry(),g.deadline());OPENSSL_cleanse(&password[0],password.size());password.clear();
            guard(same_file(config,credential_file(credential,hooks_.owner)),"resource-credential-changed");g.current();ProtectedQaArchive archive(dir_);
            auto result=retire_qa_tables(g.registry(),g,db,archive);guard(result.complete,"resource-retirement-incomplete");g.current();
            guard(same_file(config,credential_file(credential,hooks_.owner)) && same_file(started,dir_->read("qa.cleanup.started.original",4096)),"resource-cleanup-changed");
            const std::string complete="paymenter-source-qa-retirement-complete-v1\nrun="+run+"\narchive_sha256="+hash(archive.reload())+"\ntables=absent\n";
            dir_->create("qa.cleanup.complete.original",complete);g.current();dir_->create("retired.v1",complete);
            guard(dir_->read("qa.cleanup.complete.original",4096).raw==complete && dir_->read("retired.v1",4096).raw==complete,"resource-retirement-publish");return result;
        }catch(...){failure("qa.cleanup.failure.original",run);throw;}
    }
};
#ifdef __linux__
inline bool resource_native_retired(pid_t pid,const std::string& start) {
    int fd=open(("/proc/"+std::to_string(pid)+"/stat").c_str(),O_RDONLY|O_CLOEXEC|O_NONBLOCK);
    if(fd<0){if(errno==ENOENT)return true;throw ProbeError("resource-writer-lookup");}
    Fd holder(fd);auto raw=bounded(fd,8192);auto end=raw.rfind(')');guard(end!=std::string::npos && raw.substr(0,raw.find(' '))==std::to_string(pid),"resource-writer-stat");
    std::istringstream in(raw.substr(end+1));std::string value;for(int i=0;i<20;++i)guard(bool(in>>value),"resource-writer-stat");number(value);return value!=start;
}
inline bool resource_native_runtime(pid_t pid,const std::string& start,const Fields& f,uint64_t module_dev,uint64_t module_ino) {
    if(boot_id()!=f.at("boot") || target_start(pid)!=start)return false;
    const std::string base="/proc/"+std::to_string(pid);char path[PATH_MAX];auto count=readlink((base+"/exe").c_str(),path,sizeof(path));
    if(count<=0 || std::string(path,count)!=f.at("runtime_path"))return false;
    const auto runtime=image(f.at("runtime_path"),true);struct stat actual;
    if(hash(runtime.raw)!=f.at("runtime_sha256") || stat((base+"/exe").c_str(),&actual)!=0 || actual.st_dev!=runtime.identity.dev || actual.st_ino!=runtime.identity.ino)return false;
    std::istringstream status(system_file((base+"/status").c_str(),65536));std::string line;bool root=false;
    while(std::getline(status,line))if(line.compare(0,4,"Uid:")==0){std::istringstream values(line.substr(4));std::string a,b,c,d,extra;root=bool(values>>a>>b>>c>>d) && !(values>>extra) && a=="0" && b=="0" && c=="0" && d=="0";}
    if(!root)return false;
    std::istringstream maps(system_file((base+"/maps").c_str(),1048576));
    while(std::getline(maps,line)) {
        std::istringstream fields(line);std::string range,permissions,offset,device,inode,name;if(!(fields>>range>>permissions>>offset>>device>>inode))throw ProbeError("resource-module-maps");
        std::getline(fields,name);name.erase(0,name.find_first_not_of(' '));
        if(name==f.at("module_path") || name==f.at("module_path")+" (deleted)")return false;
        if(inode=="0")continue;
        auto colon=device.find(':');guard(colon!=std::string::npos,"resource-module-maps");
        if(number(inode)==module_ino && std::stoull(device.substr(0,colon),nullptr,16)==major(static_cast<dev_t>(module_dev)) &&
            std::stoull(device.substr(colon+1),nullptr,16)==minor(static_cast<dev_t>(module_dev)))return false;
    }
    return target_start(pid)==start;
}
#endif
}}
#ifndef PAYMENTER_QA_RESOURCES_NO_MAIN
#if !defined(PAYMENTER_QA_CREDENTIAL_FILE) || !defined(PAYMENTER_QA_DATABASE) || !defined(PAYMENTER_QA_SOCKET)
#error "Fixed reviewed credential/database/socket required"
#endif
int main(int argc,char**argv) {
    using namespace paymenter_qa;
    try {
#ifdef __linux__
        guard(argc==3 && geteuid()==0 && getegid()==0,"resource-linux-root-required");
        auto dir=std::make_shared<PrivateDirectory>(argv[2],Owner{0,0});struct statfs fs;
        guard(fstatfs(dir->descriptor(),&fs)==0 && (fs.f_type==0xEF53 || fs.f_type==0x58465342 || fs.f_type==0x01021994),"resource-filesystem");
        ResourceHooks hooks{Owner{0,0},boot_id,[](const std::string& path){return image(path,true);},resource_native_runtime,resource_native_retired,real("/proc/self/exe"),process_start};
        NativeQaResourceFactory factory(dir,hooks);std::string mode=argv[1];
        if(mode=="create")factory.create(PAYMENTER_QA_CREDENTIAL_FILE,PAYMENTER_QA_DATABASE,PAYMENTER_QA_SOCKET);
        else if(mode=="retire")factory.retire(PAYMENTER_QA_CREDENTIAL_FILE,PAYMENTER_QA_DATABASE,PAYMENTER_QA_SOCKET);
        else throw ProbeError("resource-mode");
        std::cout<<"native-resource-originals-retained\n";return 0;
#else
        (void)argc;(void)argv;throw ProbeError("resource-linux-root-required");
#endif
    }catch(const ProbeError& e){std::cerr<<e.what()<<'\n';}catch(...){std::cerr<<"resource-incomplete\n";}
    return 1;
}
#endif
