// Standalone fixed F1-F5 runner. It consumes already-created, installed and
// loaded QA resources. Installation, manager lifecycle and cleanup are separate
// reviewed packet steps; this executable never restarts/signals the manager.
#define PAYMENTER_QA_RESOURCES_NO_MAIN
#define PAYMENTER_QA_EVIDENCE_NO_MAIN
#include "resource_custody.cpp"
#include "case_evidence.cpp"
#include "framework_sequence.hpp"
#include "owned_child.hpp"
#include <exception>
namespace paymenter_qa { namespace {
const char* const RUN_FIELDS="intent run boot issued expires self_path self_sha256 caller_path caller_sha256 reader_path reader_sha256 resource_path resource_sha256 manager_pid manager_start authority_sha256 created_sha256 created_identity complete_sha256 complete_identity";
struct RetainedRunFile {std::shared_ptr<PrivateDirectory> directory;std::string name;File original;};
struct ControllerHooks {
    Owner owner;std::function<std::string()> boot;ImageReader images;TargetCheck target;RetiredChildCheck gone;
    std::string self_path;std::function<std::string()> self_start;
    std::function<NativeChildResult(const NativeChildCommand&,Clock::time_point,const std::function<void()>&)> child;
};
class NativeFrameworkBackend:public FrameworkRunBackend {
    const std::shared_ptr<PrivateDirectory> dir_;const ControllerHooks hooks_;const pid_t process_=getpid();
    const std::thread::id thread_=std::this_thread::get_id();const std::string self_start_;
    const File approval_,authority_,issuance_;const Fields fields_;const OwnedQaResources resources_;
    NativeContext context_;Clock::time_point end_,case_end_;uint64_t case_expires_=0;
    std::map<std::string,File> images_;std::vector<RetainedRunFile> retained_;std::vector<std::string> history_;
    unsigned reader_number_=0,case_number_=0,active_children_=0;size_t output_bytes_=0;
    std::string active_case_;File active_intent_;std::map<std::string,NativeChildResult> callers_;
    static std::string hex_bytes(const std::string& s){const char*h="0123456789abcdef";std::string out;for(unsigned char c:s){out+=h[c>>4];out+=h[c&15];}return out;}
    static std::string packed(const std::vector<std::string>& values){std::string out;for(const auto& s:values){out+=std::to_string(s.size())+":"+s;}return out;}
    void remember(std::shared_ptr<PrivateDirectory> dir,const std::string& name,size_t limit=4096){retained_.push_back({dir,name,dir->read(name,limit)});}
    void put(const std::string& name,const std::string& raw){dir_->create(name,raw);remember(dir_,name);}
    std::string run()const{return context_.fields.at("run");}
    std::string nonce(const std::string& id)const{return id=="F5"?hash(authority_.raw+"\nF5\n"):context_.fields.at("nonce_"+id);}
    void history(const std::string& id){guard(reader_connection(id),"controller-connection");if(std::find(history_.begin(),history_.end(),id)==history_.end()){guard(history_.size()<64,"controller-history");history_.push_back(id);}}
    std::string history_text()const{std::string raw;for(const auto& s:history_){if(!raw.empty())raw+=",";raw+=s;}return raw.empty()?"none":raw;}
    void check(bool fence=false) {
        guard(getpid()==process_ && std::this_thread::get_id()==thread_ && hooks_.self_start()==self_start_,"controller-process");
        context_.check();resources_.current();auto now=now_seconds();
        guard(hooks_.boot()==fields_.at("boot") && now>=number(fields_.at("issued")) && now<number(fields_.at("expires")) && Clock::now()<end_,"controller-expired");
        guard(same_file(approval_,dir_->read("run.approval.native.original",4096)) && same_file(authority_,dir_->read("authority.v1",4096)) &&
            same_file(issuance_,dir_->read("issuance.native.original",4096)),"controller-original-changed");
        guard(!dir_->exists("controller.failure.original") && !dir_->exists("retired.v1") && (fence || !dir_->exists("revoked.v1")),"controller-held");
        for(const auto& image_:images_)guard(same_file(image_.second,hooks_.images(image_.first)),"controller-image-changed");
        std::map<PrivateDirectory*,std::vector<const RetainedRunFile*>> groups;
        for(const auto& original:retained_)groups[original.directory.get()].push_back(&original);
        for(const auto& group:groups) {
            std::vector<std::pair<std::string,size_t>> names;for(const auto* original:group.second)names.push_back({original->name,32768});
            auto files=group.first->read_batch(names);
            for(size_t i=0;i<files.size();++i)guard(same_file(files[i],group.second[i]->original),"controller-evidence-changed");
        }
        guard(hooks_.target(static_cast<pid_t>(number(fields_.at("manager_pid"))),fields_.at("manager_start"),context_.fields),"controller-manager");
        if(!active_case_.empty())guard(Clock::now()<case_end_ && now_seconds()<case_expires_ && same_file(active_intent_,dir_->read("intent.v1",4096)),"controller-case-expired");
    }
    NativeChildCommand command(const std::string& role,std::vector<std::string> argv)const {
        auto path=fields_.at(role+"_path");argv.insert(argv.begin(),path);
        auto pinned=images_.at(path);struct stat s;
        if(pinned.program_binding.empty())guard(lstat(path.c_str(),&s)==0,"controller-command-image");
        else {
            ProgramImage reference(path,hooks_.owner.uid,hooks_.owner.gid);reference.unchanged();s=reference.status();
            guard(reference.binding()==pinned.program_binding,"controller-command-reference");
        }
        guard(stamp(s)==pinned.identity,"controller-command-image");
        return {path,argv,{"PATH=/usr/bin:/bin","LANG=C","LC_ALL=C","LD_LIBRARY_PATH=/usr/local/mgr5/lib"},hooks_.owner.uid,hooks_.owner.gid,child_image_identity(s),pinned.program_binding};
    }
    NativeChildResult launch(const std::string& label,const NativeChildCommand& command_,Clock::time_point end,const std::function<void()>& during) {
        check();guard(active_children_<2 && output_bytes_<=1048576-24576,"controller-budget");
        const auto image_=hooks_.images(command_.path);auto before="paymenter-source-qa-owned-command-v1\nrun="+run()+"\nslot="+label+"\nboot="+fields_.at("boot")+
            "\nparent="+std::to_string(process_)+"\nparent_start="+self_start_+"\nimage_sha256="+hash(image_.raw)+"\nimage_identity="+resource_identity(image_)+
            "\nprogram_reference_sha256="+hash(image_.program_binding)+"\nargv_hex="+hex_bytes(packed(command_.argv))+"\nenvironment_hex="+hex_bytes(packed(command_.environment))+"\n";
        put("capture."+label+".command.original",before);++active_children_;
        NativeChildResult result;
        try{result=hooks_.child(command_,std::min(end,end_),during);}catch(...){--active_children_;throw;}
        --active_children_;output_bytes_+=result.stdout_bytes.size()+result.stderr_bytes.size();
        // Framing retains empty streams without pretending bytes existed.
        for(const auto& stream:{std::make_pair("stdout",result.stdout_bytes),std::make_pair("stderr",result.stderr_bytes)}){
            auto name="capture."+label+"."+stream.first+".original";
            dir_->create_capture(name,"paymenter-source-qa-stream-v1\n"+stream.second);remember(dir_,name,32768);
        }
        put("capture."+label+".original","paymenter-source-qa-owned-capture-v1\nrun="+run()+"\nslot="+label+"\nboot="+result.boot+"\npid="+std::to_string(result.pid)+
            "\nstart="+result.start+"\nparent="+std::to_string(result.parent)+"\ngroup="+std::to_string(result.group)+"\nsession="+std::to_string(result.session)+
            "\nexit="+std::to_string(result.exitcode)+"\nexit_known="+(result.exit_known?"1":"0")+"\nreaped="+(result.reaped?"1":"0")+
            "\nstdout_eof="+(result.stdout_eof?"1":"0")+"\nstderr_eof="+(result.stderr_eof?"1":"0")+"\nexec_confirmed="+(result.exec_confirmed?"1":"0")+
            "\nsignal_sent="+(result.signal_sent?"1":"0")+"\nfailure="+(result.failure.empty()?"none":result.failure)+
            "\nstdout_sha256="+hash(result.stdout_bytes)+"\nstderr_sha256="+hash(result.stderr_bytes)+"\ncommand_sha256="+hash(before)+"\n");
        guard(output_bytes_<=1048576 && result.linux_identity && result.boot==fields_.at("boot") && result.parent==process_ &&
            result.pid>1 && result.pid!=static_cast<pid_t>(number(fields_.at("manager_pid"))) && result.group==result.pid && result.session==result.pid &&
            result.reaped && result.exit_known && result.exec_confirmed && result.stdout_eof && result.stderr_eof && !result.signal_sent &&
            (result.failure.empty() || result.failure=="child-exit") && hooks_.gone(result.pid,result.start),"controller-caller-incomplete");
        check();return result;
    }
    FrameworkCaseEvidence evidence()const{return FrameworkCaseEvidence(dir_,hooks_.owner,hooks_.boot,hooks_.images,hooks_.target,hooks_.gone);}
    std::shared_ptr<PrivateDirectory> read_directory(const std::string& slot) {
        dir_->unchanged();guard(slot.size()<60 && slot.find_first_not_of("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-")==std::string::npos,"controller-read-slot");
        guard(mkdirat(dir_->descriptor(),slot.c_str(),0700)==0 && fsync(dir_->descriptor())==0,"controller-read-directory");
        return std::make_shared<PrivateDirectory>(dir_->path()+"/"+slot,hooks_.owner);
    }
    FrameworkRows read(const std::string& slot,const std::string& id,const std::string& phase,bool audit_) {
        check();guard(reader_number_<24,"controller-reader-budget");auto reader_id="R"+std::to_string(reader_number_++);
        auto rd=read_directory(slot);auto issued=now_seconds();auto expires=std::min<uint64_t>(issued+10,active_case_.empty()?number(fields_.at("expires")):case_expires_);
        guard(expires>issued,"controller-reader-window");
        std::string writer="none";
        if(!audit_) {auto w=parse(dir_->read("writer."+id+".original",4096).raw,"paymenter-source-qa-writer-v1","run case nonce facts connection pid start intent_sha256");writer=w.at("connection");history(writer);}
        Fields f{{"intent","observe-framework"},{"kind",audit_?"audit":"observation"},{"run",run()},{"request",hash(approval_.raw+"\n"+slot+"\n"+reader_id)},
            {"boot",fields_.at("boot")},{"case",audit_?"none":id},{"phase",phase},{"facts",audit_?hash(context_.registry.raw):context_.fields.at("facts_"+id)},
            {"writer",writer},{"previous",history_text()},{"issued",std::to_string(issued)},{"expires",std::to_string(expires)}};
        std::string order="intent kind run request boot case phase facts writer previous issued expires";
        if(audit_)for(const auto& c:{"F1","F2","F3","F4"}){auto key=std::string("facts_")+c;f[key]=context_.fields.at(key);order+=" "+key;}
        if(audit_) {
            size_t index=0;for(const auto& table:{"business","journal"}) {
                auto schema=resources_.registry.definitions[index++];auto key=std::string(table)+"_sha256";f[key]=hash(schema);order+=" "+key;
                auto name=std::string("qa.schema.")+table+".original";rd->create(name,schema);remember(rd,name);
            }
        }
        auto original=encode_fields(audit_?"paymenter-source-qa-audit-approval-native-v1":"paymenter-source-qa-read-approval-native-v1",f,order);
        rd->create("read.approval.native.original",original);remember(rd,"read.approval.native.original");
        auto captured=launch(reader_id,command("reader",{rd->path()}),std::min(Clock::now()+std::chrono::seconds(expires-issued),active_case_.empty()?end_:case_end_),[&]{check();});
        guard(captured.exitcode==0 && captured.failure.empty() && captured.stdout_bytes=="native-read-retained\n" && captured.stderr_bytes.empty() && !rd->exists("read.failure.original"),"controller-reader-failed");
        auto result=rd->read("read.original",4096);auto row_at=result.raw.find("\nrow=");auto header=row_at==std::string::npos?result.raw:result.raw.substr(0,row_at+1);
        std::string names="kind run request boot case phase facts writer connection started finished isolation autocommit transaction_open approval_sha256";
        if(audit_)names+=" facts_F1 facts_F2 facts_F3 facts_F4 business_sha256 journal_sha256";
        auto metadata=parse(header,"paymenter-source-qa-read-original-native-v1",names);
        for(const auto& key:{"kind","run","request","boot","case","phase","facts","writer"})guard(metadata.at(key)==f.at(key),"controller-read-binding");
        guard(metadata.at("approval_sha256")==hash(original) && metadata.at("isolation")=="READ COMMITTED" && metadata.at("autocommit")=="1" && metadata.at("transaction_open")=="0","controller-read-state");
        auto started=parse(rd->read("read.started.original",4096).raw,"paymenter-source-qa-read-started-native-v1","request approval_sha256");
        auto complete=parse(rd->read("read.complete.original",4096).raw,"paymenter-source-qa-read-complete-native-v1","request original_sha256");
        guard(started.at("request")==f.at("request") && started.at("approval_sha256")==hash(original) && complete.at("request")==f.at("request") && complete.at("original_sha256")==hash(result.raw),"controller-read-completion");
        auto connection=metadata.at("connection");guard(reader_connection(connection) && std::find(history_.begin(),history_.end(),connection)==history_.end() && connection!=writer,"controller-reader-reused");history(connection);
        auto began=number(metadata.at("started")),finished=number(metadata.at("finished"));guard(began>=issued && began<=finished && finished<expires && finished<=now_seconds(),"controller-read-times");
        if(audit_)for(const auto& c:{"F1","F2","F3","F4"})guard(metadata.at(std::string("facts_")+c)==context_.fields.at(std::string("facts_")+c),"controller-audit-facts");
        if(audit_)for(const auto& table:{"business","journal"})guard(metadata.at(std::string(table)+"_sha256")==f.at(std::string(table)+"_sha256"),"controller-audit-schema");
        FrameworkRows rows;if(row_at!=std::string::npos){std::istringstream lines(result.raw.substr(row_at+1));std::string line;while(std::getline(lines,line)){guard(line.find("row=")==0 && rows.size()<8,"controller-read-rows");rows.push_back(line.substr(4));}}
        std::sort(rows.begin(),rows.end());
        for(const auto& name:{"read.started.original","read.original","read.complete.original"})remember(rd,name);
        check();return rows;
    }
public:
    NativeFrameworkBackend(std::shared_ptr<PrivateDirectory> dir,const ControllerHooks& hooks):dir_(dir),hooks_(hooks),self_start_(hooks.self_start()),
        approval_(dir->read("run.approval.native.original",4096)),authority_(dir->read("authority.v1",4096)),issuance_(dir->read("issuance.native.original",4096)),
        fields_(parse(approval_.raw,"paymenter-source-qa-run-approval-native-v1",RUN_FIELDS)),resources_(dir),context_(dir,hooks.boot,hooks.images) {
        auto issued=number(fields_.at("issued")),expires=number(fields_.at("expires")),now=now_seconds();
        guard(fields_.at("intent")=="run-framework-F1-F5" && fields_.at("run")==run() && fields_.at("boot")==context_.fields.at("boot") && issued<expires &&
            expires-issued<=600 && issued>=context_.admitted.issued && expires<=context_.admitted.expires && now>=issued && now<expires,"controller-approval");
        end_=std::min(context_.deadline,Clock::now()+std::chrono::seconds(expires-now));
        guard(authority_.raw==context_.authority() && issuance_.raw==context_.receipt() && fields_.at("authority_sha256")==hash(authority_.raw),"controller-authority");
        guard(fields_.at("self_path")==hooks.self_path && fields_.at("resource_path")==resources_.create_approval.at("issuer_path") &&
            fields_.at("resource_sha256")==resources_.create_approval.at("issuer_sha256"),"controller-producer");
        const auto created=resources_.created_original(),complete=resources_.complete_original();
        guard(fields_.at("created_sha256")==hash(created.raw) && fields_.at("created_identity")==resource_identity(created) &&
            fields_.at("complete_sha256")==hash(complete.raw) && fields_.at("complete_identity")==resource_identity(complete),"controller-resource-custody");
        resource_pid(fields_.at("manager_pid"));number(fields_.at("manager_start"));number(self_start_);
        std::set<std::pair<dev_t,ino_t>> identities;
        for(const auto& role:{"self","caller","reader","resource"}) {
            auto path=fields_.at(std::string(role)+"_path");canonical(path);auto original=hooks.images(path);
            guard(original.raw.compare(0,4,"\177ELF")==0 && hash(original.raw)==fields_.at(std::string(role)+"_sha256") && path!=context_.fields.at("module_path") &&
                path!=context_.fields.at("runtime_path") && identities.insert({original.identity.dev,original.identity.ino}).second,"controller-program-image");images_.emplace(path,original);
        }
        for(const auto& name:{"controller.started.original","controller.complete.original","controller.failure.original","intent.v1","refusal.F5.original","barrier.original","release.F4.v1"})guard(!dir_->exists(name),"controller-reused");
        for(const auto& id:{"F1","F2","F3","F4"})for(const auto& prefix:{"intent.","writer.","case."})guard(!dir_->exists(std::string(prefix)+id+(std::string(prefix)=="case."?".approval.native.original":".original")),"controller-reused");
        for(const auto& id:{"F1","F2","F3","F4"})for(const auto& stage:{"business","journal","inject-business","inject-journal","barrier"})
            guard(!dir_->exists(std::string("checkpoint.")+id+"."+stage+".original"),"controller-reused-checkpoint");
        current();
        for(const auto& role:{"self","caller","reader","resource"}) {
            auto ref=images_.at(fields_.at(std::string(role)+"_path")).program_binding;
            if(!ref.empty()){auto name=std::string("capture.image.")+role+".original";dir_->create_capture(name,ref);remember(dir_,name,32768);}
        }
        put("controller.started.original","paymenter-source-qa-controller-started-v1\nrun="+run()+"\napproval_sha256="+hash(approval_.raw)+"\npid="+std::to_string(process_)+"\nstart="+self_start_+"\n");current();
    }
    void current()override{check();}
    FrameworkRows audit(const std::string& slot)override{return read("audit."+slot,"none","after",true);}
    FrameworkRows expected(const std::string& id)override {
        if(id!="F1" && id!="F4")return {};
        return {"business,"+run()+","+id+",0.0100,"+context_.fields.at("facts_"+id),"journal,"+run()+","+id+","+id+","+context_.fields.at("facts_"+id)};
    }
    void begin(const std::string& id)override {
        current();guard(active_case_.empty() && id=="F"+std::to_string(case_number_+1) && case_number_<4,"controller-case-order");
        auto issued=now_seconds();case_expires_=std::min<uint64_t>(issued+30,number(fields_.at("expires")));guard(case_expires_>issued,"controller-case-window");
        case_end_=std::min(end_,Clock::now()+std::chrono::seconds(case_expires_-issued));
        Fields f{{"intent","issue-framework-case"},{"run",run()},{"case",id},{"nonce",nonce(id)},{"facts",context_.fields.at("facts_"+id)},{"pid",fields_.at("manager_pid")},
            {"start",fields_.at("manager_start")},{"boot",fields_.at("boot")},{"issued",std::to_string(issued)},{"expires",std::to_string(case_expires_)},{"authority_sha256",hash(authority_.raw)}};
        put("case."+id+".approval.native.original",encode_fields("paymenter-source-qa-approve-case-native-v1",f,"intent run case nonce facts pid start boot issued expires authority_sha256"));
        NativeControlIssuer(dir_,hooks_.boot,hooks_.images,hooks_.target).issue_case(id);
        active_intent_=dir_->read("intent.v1",4096);active_case_=id;remember(dir_,"intent."+id+".original");current();
    }
    void caller(const std::string& id,const std::function<void()>& during)override {
        current();guard((id==active_case_ && case_number_<4) || (id=="F5" && active_case_.empty() && case_number_==4),"controller-caller-order");
        std::exception_ptr callback_error;
        auto result=launch(id,command("caller",{"-m","billmgr","-o","xml","paymenter.qa.transaction","qa_run="+run(),"qa_case="+id,"qa_nonce="+nonce(id)}),
            id=="F5"?std::min(end_,Clock::now()+std::chrono::seconds(30)):case_end_,[&]{try{during();}catch(...){callback_error=std::current_exception();throw;}});
        if(callback_error)std::rethrow_exception(callback_error);
        guard(result.stderr_bytes.empty() && !result.stdout_bytes.empty(),"controller-caller-response");
        if(id=="F1" || id=="F4")guard(result.exitcode==0 && result.failure.empty(),"controller-caller-response");
        else guard(result.exitcode>=0,"controller-caller-response");
        callers_.emplace(id,result);
        if(id=="F1") {
            dir_->create_capture("capture.F1.reply.original","paymenter-source-qa-stream-v1\n"+result.stdout_bytes);remember(dir_,"capture.F1.reply.original",32768);
            // The native reply remains source-private and is never returned to an
            // external collector. Only a fresh-reader reconciliation is reported.
            dir_->create_capture("capture.F1.loss.original","paymenter-source-qa-loss-v1\nmode=collector-result-withheld\n");remember(dir_,"capture.F1.loss.original");
            // Existing recovery binder uses the unframed nonempty original.
            dir_->create_caller_reply("caller.F1.stdout",result.stdout_bytes);remember(dir_,"caller.F1.stdout",16384);
            put("caller.F1.original","paymenter-source-qa-caller-capture-v1\nrun="+run()+"\ncase=F1\nboot="+result.boot+"\npid="+std::to_string(result.pid)+"\nstart="+result.start+
                "\nexit=0\nstdout_eof=1\nstderr_eof=1\nstdout_sha256="+hash(result.stdout_bytes)+"\nstderr_bytes=0\ndelivery=withheld\nnonce="+nonce(id)+
                "\nfacts="+context_.fields.at("facts_F1")+"\nintent_sha256="+hash(active_intent_.raw)+"\n");
        }
    }
    bool barrier_ready()override {current();return dir_->exists("checkpoint.F4.barrier.original");}
    FrameworkRows observe(const std::string& id,const std::string& slot)override {
        current();auto rows=read("read."+id+"."+slot,id,slot=="barrier"?"barrier":"after",false);
        if(slot!="reconcile"){evidence().bind(id,slot);remember(dir_,"observed."+id+"."+slot+".original");}return rows;
    }
    void release_barrier()override {
        current();guard(active_case_=="F4","controller-barrier-case");
        auto seal=dir_->read("observed.F4.barrier.original",4096);
        put("barrier.original","paymenter-source-qa-controller-barrier-v1\nrun="+run()+"\ncase=F4\nobservation_sha256="+hash(seal.raw)+"\n");
        auto original=dir_->read("barrier.original",4096);
        put("release.F4.v1","paymenter-source-qa-release-v1\nrun="+run()+"\ncase=F4\nnonce="+nonce("F4")+"\noriginal_sha256="+hash(original.raw)+"\n");current();
    }
    void checkpoints(const std::string& id)override {
        current();auto writer=dir_->read("writer."+id+".original",4096);auto intent=dir_->read("intent."+id+".original",4096);
        std::vector<std::string> stages{"business"};if(id!="F2")stages.push_back("journal");if(id=="F4")stages.push_back("barrier");if(id=="F2")stages.push_back("inject-business");if(id=="F3")stages.push_back("inject-journal");
        for(const auto& stage:stages){auto name="checkpoint."+id+"."+stage+".original";auto original=dir_->read(name,4096);
            auto f=parse(original.raw,"paymenter-source-qa-checkpoint-v1","run case nonce facts stage writer_sha256 intent_sha256");
            guard(f.at("run")==run() && f.at("case")==id && f.at("nonce")==nonce(id) && f.at("facts")==context_.fields.at("facts_"+id) && f.at("stage")==stage &&
                f.at("writer_sha256")==hash(writer.raw) && f.at("intent_sha256")==hash(intent.raw),"controller-post-effect-checkpoint");remember(dir_,name);}
        for(const auto& stage:{"business","journal","inject-business","inject-journal","barrier"})if(std::find(stages.begin(),stages.end(),stage)==stages.end())guard(!dir_->exists("checkpoint."+id+"."+stage+".original"),"controller-unexpected-checkpoint");
        remember(dir_,"writer."+id+".original");current();
    }
    void withhold_and_reconcile(const std::string& id)override {current();guard(id=="F1","controller-loss-case");evidence().withhold(id);remember(dir_,"withheld.F1.original");guard(observe(id,"reconcile")==expected(id),"controller-recovery-rows");evidence().recover(id);remember(dir_,"recovered.F1.original");current();}
    void advance(const std::string& id)override {
        current();guard(active_case_==id && callers_.count(id) && callers_.at(id).reaped,"controller-advance");
        const auto original=active_intent_;auto archived=dir_->read("intent."+id+".original",4096);guard(original.raw==archived.raw,"controller-intent-archive");
        put("advance."+id+".original","paymenter-source-qa-advance-v1\nrun="+run()+"\ncase="+id+"\nintent_sha256="+hash(original.raw)+"\nintent_identity="+resource_identity(original)+"\n");
        dir_->create("revoked.v1","paymenter-source-qa-advance-fence-v1\nrun="+run()+"\ncase="+id+"\n");auto fence=dir_->read("revoked.v1",4096);
        check(true);guard(same_file(original,dir_->read("intent.v1",4096)),"controller-intent-changed");
        guard(unlinkat(dir_->descriptor(),"intent.v1",0)==0 && fsync(dir_->descriptor())==0,"controller-intent-retirement");
        active_case_.clear();++case_number_;check(true);remove_fence(dir_,fence);current();
    }
    void refusal()override {
        current();guard(active_case_.empty() && case_number_==4 && callers_.count("F5"),"controller-refusal-order");
        auto f=parse(dir_->read("refusal.F5.original",4096).raw,"paymenter-source-qa-refusal-v1","run case nonce pid start authority_sha256");
        guard(f.at("run")==run() && f.at("case")=="F5" && f.at("nonce")==nonce("F5") && f.at("pid")==fields_.at("manager_pid") && f.at("start")==fields_.at("manager_start") &&
            f.at("authority_sha256")==hash(authority_.raw),"controller-refusal-proof");
        for(const auto& name:{"intent.F5.original","writer.F5.original","intent.v1"}){guard(!dir_->exists(name),"controller-refusal-wrote");}remember(dir_,"refusal.F5.original");current();
    }
    void complete()override {
        current();guard(case_number_==4 && callers_.size()==5 && active_case_.empty() && active_children_==0,"controller-incomplete");
        // Case evidence remains provisional until the separately approved native
        // unload/restart/retirement and full qualification review are complete.
        put("controller.complete.original","paymenter-source-qa-controller-complete-v1\nrun="+run()+"\ncases=F1,F2,F3,F4,F5\ncollector_recovery=observed\nretired=0\nqualification=not-issued\n");
        current();dir_->create("revoked.v1","paymenter-source-qa-controller-finished-v1\nrun="+run()+"\n");
    }
    void fail()noexcept override {
        if(getpid()!=process_)return;
        std::string reason="controller-unknown";
        auto error=std::current_exception();if(error)try{std::rethrow_exception(error);}
        catch(const ProbeError& e){reason=e.what();}catch(const ChildError& e){reason=e.what();}catch(const SequenceError& e){reason=e.what();}catch(...){}
        if(reason.size()>80 || reason.find_first_not_of("abcdefghijklmnopqrstuvwxyz0123456789-")!=std::string::npos)reason="controller-unknown";
        try{if(!dir_->exists("controller.failure.original"))dir_->create("controller.failure.original","paymenter-source-qa-controller-incomplete-v1\nreason="+reason+"\n");}catch(...){}
        try{if(!dir_->exists("revoked.v1"))dir_->create("revoked.v1","paymenter-source-qa-controller-failed-v1\n");}catch(...){}
    }
};
#ifdef __linux__
inline ControllerHooks native_controller_hooks(const std::shared_ptr<PrivateDirectory>& dir) {
    return {Owner{0,0},boot_id,native_images(dir),native_target,native_child_gone,real("/proc/self/exe"),process_start,
        [](const NativeChildCommand& c,Clock::time_point end,const std::function<void()>& during){NativeOwnedChild child(c,end);return child.run(during);}};
}
#endif
}}
#ifndef PAYMENTER_QA_CONTROLLER_NO_MAIN
int main(int argc,char**argv){using namespace paymenter_qa;try{
#ifdef __linux__
    guard(argc==2 && geteuid()==0 && getegid()==0,"controller-linux-root-required");auto dir=std::make_shared<PrivateDirectory>(argv[1],Owner{0,0});
    struct statfs fs;guard(fstatfs(dir->descriptor(),&fs)==0 && (fs.f_type==0xEF53 || fs.f_type==0x58465342 || fs.f_type==0x01021994),"controller-filesystem");
    auto hooks=native_controller_hooks(dir);NativeFrameworkBackend backend(dir,hooks);FixedFrameworkSequence sequence(backend);sequence.run();
    std::cout<<"native-framework-evidence-retained\n";return 0;
#else
    (void)argc;(void)argv;throw ProbeError("controller-linux-root-required");
#endif
}catch(const std::exception& e){(void)e;std::cerr<<"controller-held\n";return 1;}}
#endif
