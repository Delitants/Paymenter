// Real protected-file/custody paths. SDK, database, peer identity and transport
// are authored boundaries; successful execution is NOT native qualification.
#define PAYMENTER_QA_CONTROLLER_NO_MAIN
#include "../native/run_controller.cpp"
#include <iostream>
using namespace paymenter_qa;
void check(bool value){if(!value)throw std::runtime_error("assertion");}
int main(int argc,char**argv){try {
    check(argc==3);const std::string mode=argv[1],root=argv[2],run(64,'a'),boot="11111111-1111-1111-1111-111111111111";
    auto dir=std::make_shared<PrivateDirectory>(root,Owner{getuid(),getgid()});auto now=now_seconds();
    auto write=[&](const std::string& n,const std::string& raw){dir->create(n,raw);};
    auto images=[&](const std::string& path){return dir->read(path.substr(path.rfind('/')+1),16777216);};
    for(const auto& name:{"module","runtime","resource","self","caller","reader"})write(std::string(name)+".image",std::string("\177ELF")+name);
    auto image_fields=[&](Fields& f,const std::string& role,const std::string& file){f[role+"_path"]=root+"/"+file+".image";f[role+"_sha256"]=hash(images(f.at(role+"_path")).raw);};
    auto registry="paymenter-source-qa-registry-native-v1\nrun="+run+"\ntables=paymenter_qa_business_v1,paymenter_qa_journal_v1\nobjects=none\ndiagnostics="+REGISTRY_DIAGNOSTICS+"\n";write("registry.original",registry);
    Fields build{{"run",run}};image_fields(build,"module","module");image_fields(build,"runtime","runtime");
    auto build_raw=encode_fields("paymenter-source-qa-build-native-v1",build,"run module_path module_sha256 runtime_path runtime_sha256");write("build.original",build_raw);
    Fields a=build;a["intent"]="issue-framework-authority";a["boot"]=boot;a["actor_hex"]="66696374696f6e616c2d61646d696e";a["issued"]=std::to_string(now-1);a["expires"]=std::to_string(now+500);a["registry_sha256"]=hash(registry);a["build_sha256"]=hash(build_raw);
    for(int i=1;i<=4;++i){a["nonce_F"+std::to_string(i)]=std::string(64,'0'+i);a["facts_F"+std::to_string(i)]=std::string(64,'c');}
    auto approval=encode_fields("paymenter-source-qa-approve-authority-native-v1",a,AUTH_FIELDS);write("approval.native.original",approval);
    Fields c{{"intent","create-owned-qa"},{"run",run},{"boot",boot},{"issued",std::to_string(now)},{"expires",std::to_string(now+25)},{"authority_approval_sha256",hash(approval)},
        {"registry_sha256",hash(registry)},{"build_sha256",hash(build_raw)},{"manager_pid","2"},{"manager_start","99"},{"ddl_sha256",resource_ddl_hash()},
        {"issuer_path",root+"/resource.image"},{"issuer_sha256",hash(images(root+"/resource.image").raw)}};
    write("qa.create.approval.original",encode_fields("paymenter-source-qa-create-approval-v1",c,CREATE_FIELDS));write("credential","[client]\npassword=fictional-secret\n");check(chmod((root+"/credential").c_str(),0400)==0);
    test_resource_mode=true;test_rows=false;
    ResourceHooks rh{Owner{getuid(),getgid()},[&]{return boot;},images,[](pid_t,const std::string&,const Fields&,uint64_t,uint64_t){return true;},[](pid_t,const std::string&){return true;},root+"/resource.image",[]{return "101";}};
    NativeQaResourceFactory(dir,rh).create(root+"/credential","qa","/fictional.sock");
    auto target=[](pid_t pid,const std::string& start,const Fields&){return pid==2 && start=="99";};
    NativeControlIssuer(dir,[&]{return boot;},images,target).issue_authority();auto authority=dir->read("authority.v1",4096);
    auto created=dir->read("qa.resources.created.original",4096),complete=dir->read("qa.resources.complete.original",4096);
    Fields f{{"intent","run-framework-F1-F5"},{"run",run},{"boot",boot},{"issued",std::to_string(now)},{"expires",std::to_string(now+450)},{"manager_pid","2"},{"manager_start","99"},
        {"authority_sha256",hash(authority.raw)},{"created_sha256",hash(created.raw)},{"created_identity",resource_identity(created)},
        {"complete_sha256",hash(complete.raw)},{"complete_identity",resource_identity(complete)}};
    for(const auto& role:{"self","caller","reader","resource"})image_fields(f,role,role);
    if(mode=="wrong-producer")f["resource_path"]=f["reader_path"];
    write("run.approval.native.original",encode_fields("paymenter-source-qa-run-approval-native-v1",f,RUN_FIELDS));
    FrameworkRows committed;unsigned launches=0,reader_number=0,caller_count=0;bool reached=false;
    ControllerHooks hooks{Owner{getuid(),getgid()},[&]{return boot;},images,target,[](pid_t,const std::string&){return true;},root+"/self.image",[]{return "102";},
        [&](const NativeChildCommand& cmd,Clock::time_point,const std::function<void()>& during){
            ++launches;NativeChildResult r;r.pid=1000+launches;r.parent=getpid();r.group=r.session=r.pid;r.start="201";r.boot=boot;
            r.linux_identity=r.exit_known=r.reaped=r.stdout_eof=r.stderr_eof=r.exec_confirmed=true;
            if(cmd.path==root+"/reader.image") {
                auto rd=std::make_shared<PrivateDirectory>(cmd.argv.at(1),Owner{getuid(),getgid()});auto approval_=rd->read("read.approval.native.original",4096);
                bool audit_=approval_.raw.find("paymenter-source-qa-audit-")==0;
                auto af=parse(approval_.raw,audit_?"paymenter-source-qa-audit-approval-native-v1":"paymenter-source-qa-read-approval-native-v1",audit_?
                    "intent kind run request boot case phase facts writer previous issued expires facts_F1 facts_F2 facts_F3 facts_F4 business_sha256 journal_sha256":"intent kind run request boot case phase facts writer previous issued expires");
                Fields out;for(const auto& key:{"kind","run","request","boot","case","phase","facts","writer"})out[key]=af.at(key);
                out["connection"]=std::to_string(mode=="reused-reader"?500:500+reader_number++);out["started"]=out["finished"]=std::to_string(now_seconds());out["isolation"]="READ COMMITTED";out["autocommit"]="1";out["transaction_open"]="0";out["approval_sha256"]=hash(approval_.raw);
                std::string order="kind run request boot case phase facts writer connection started finished isolation autocommit transaction_open approval_sha256";
                if(audit_)for(const auto& key:{"facts_F1","facts_F2","facts_F3","facts_F4","business_sha256","journal_sha256"}){order+=" "+std::string(key);out[key]=af.at(key);}
                auto raw=encode_fields("paymenter-source-qa-read-original-native-v1",out,order);
                if(audit_){for(const auto& row:committed)raw+="row="+row+"\n";}
                else if(af.at("phase")!="barrier"){for(const auto& row:committed)if(row.find(","+af.at("case")+",")!=std::string::npos)raw+="row="+row+"\n";}
                else if(mode=="visible-barrier")raw+="row=business,"+run+",F4,0.0100,"+std::string(64,'c')+"\n";
                rd->create("read.started.original","paymenter-source-qa-read-started-native-v1\nrequest="+af.at("request")+"\napproval_sha256="+hash(approval_.raw)+"\n");
                rd->create("read.original",raw);rd->create("read.complete.original","paymenter-source-qa-read-complete-native-v1\nrequest="+af.at("request")+"\noriginal_sha256="+hash(raw)+"\n");r.stdout_bytes="native-read-retained\n";
            } else {
                ++caller_count;check(cmd.argv.size()==9 && cmd.argv.at(4)=="xml" && cmd.argv.at(5)=="paymenter.qa.transaction");
                auto id=cmd.argv.at(7).substr(8);auto nonce_=cmd.argv.at(8).substr(9);r.stdout_bytes="<fictional-local-boundary/>\n";
                if(id=="F5") {
                    write("refusal.F5.original","paymenter-source-qa-refusal-v1\nrun="+run+"\ncase=F5\nnonce="+nonce_+"\npid=2\nstart=99\nauthority_sha256="+hash(authority.raw)+"\n");
                    if(mode=="F5-mutated")committed.clear();
                } else {
                    auto intent=dir->read("intent.v1",4096);auto writer="paymenter-source-qa-writer-v1\nrun="+run+"\ncase="+id+"\nnonce="+nonce_+"\nfacts="+std::string(64,'c')+"\nconnection=21\npid=2\nstart=99\nintent_sha256="+hash(intent.raw)+"\n";
                    write("writer."+id+".original",writer);
                    auto stage=[&](const std::string& value){write("checkpoint."+id+"."+value+".original","paymenter-source-qa-checkpoint-v1\nrun="+run+"\ncase="+id+"\nnonce="+nonce_+"\nfacts="+std::string(64,'c')+"\nstage="+value+"\nwriter_sha256="+hash(writer)+"\nintent_sha256="+hash(intent.raw)+"\n");};
                    stage("business");if(id!="F2")stage("journal");if(id=="F2" && mode!="injection-not-reached")stage("inject-business");if(id=="F3")stage("inject-journal");
                    if(id=="F4") {if(mode!="missing-barrier")stage("barrier");during();}
                    if(id=="F1" || id=="F4"){committed.push_back("business,"+run+","+id+",0.0100,"+std::string(64,'c'));committed.push_back("journal,"+run+","+id+","+id+","+std::string(64,'c'));}
                    if(mode=="capture-overflow" && id=="F1")r.failure="stdout-limit";
                    if(mode=="lost-native-child" && id=="F1")r.reaped=false;
                    if(mode=="large-reply" && id=="F1")r.stdout_bytes=std::string(12000,'x');
                }
            }
            return r;
        }};
    bool good=mode=="normal" || mode=="large-reply";
    try {NativeFrameworkBackend backend(dir,hooks);FixedFrameworkSequence sequence(backend);sequence.run();reached=true;}catch(const std::exception& e){if(good)std::cerr<<e.what()<<'\n';}
    check(reached==good);if(good)check(caller_count==5 && dir->exists("controller.complete.original") && dir->exists("revoked.v1") && !dir->exists("controller.failure.original"));
    else if(mode=="wrong-producer")check(launches==0 && !dir->exists("controller.started.original"));
    else check(dir->exists("controller.failure.original") && dir->exists("revoked.v1") && !dir->exists("controller.complete.original"));
    return 0;
}catch(const std::exception&e){std::cerr<<e.what()<<'\n';return 1;}}
