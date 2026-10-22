#define PAYMENTER_QA_RESOURCES_NO_MAIN
#include "../native/resource_custody.cpp"
#include <iostream>
#include <sys/wait.h>
using namespace paymenter_qa;
static void check(bool b){if(!b)throw std::runtime_error("test assertion");}
template<class F>static void refuses(F f){bool held=false;try{f();}catch(const std::exception&){held=true;}check(held);}
int main(int argc,char**argv){
 try{
    auto began=Clock::now();check(argc==3);test_mode=argv[1];std::string root=argv[2];test_resource_mode=true;test_rows=false;
    auto dir=std::make_shared<PrivateDirectory>(root,Owner{getuid(),getgid()});bool cleanup=false,changed=false;unsigned cleanup_checks=0;
    auto replace=[&](const std::string& name){auto f=dir->read(name,4096);check(rename((root+"/"+name).c_str(),(root+"/"+name+".old").c_str())==0);dir->create(name,f.raw);};
    auto images=[&](const std::string& path){return dir->read(path.substr(path.rfind('/')+1),16777216);};
    ResourceHooks hooks{Owner{getuid(),getgid()},[]{return "11111111-1111-1111-1111-111111111111";},images,
        [&](pid_t pid,const std::string& start,const Fields&,uint64_t,uint64_t){
            if(!cleanup)return pid==2 && start=="99" && test_mode!="create-live-module";
            ++cleanup_checks;
            if(test_mode=="cleanup-revocation-after-open" && cleanup_checks==2)replace("revoked.v1");
            return pid==4 && start=="301" && test_mode!="cleanup-mapped-module" && test_mode!="cleanup-wrong-manager";
        },[&](pid_t pid,const std::string&){if(test_mode=="cleanup-unknown-writer")throw ProbeError("test-unknown-lookup");return !(test_mode=="cleanup-live-writer" && pid==3);},
        root+"/issuer.image",[]{return "101";}};
    NativeQaResourceFactory factory(dir,hooks);
    if(test_mode=="collision")test_resource_tables[0]=true;
    if(test_mode=="create-approval-replaced" || test_mode=="create-archive-failure" || test_mode=="create-schema-replaced" || test_mode=="create-schema-content-changed")test_query_hook=[&](const std::string& sql){
        if(changed)return;
        if(test_mode=="create-approval-replaced"){changed=true;replace("qa.create.approval.original");}
        else if(test_mode=="create-archive-failure" && sql.find("SHOW CREATE TABLE")==0){changed=true;dir->create("qa.schema.business.original","colliding original\n");}
        else if((test_mode=="create-schema-replaced" || test_mode=="create-schema-content-changed") && dir->exists("qa.schema.business.original")){
            changed=true;replace("qa.schema.business.original");
            if(test_mode=="create-schema-content-changed"){int fd=open((root+"/qa.schema.business.original").c_str(),O_WRONLY|O_TRUNC);check(fd>=0);check(write(fd,"changed schema\n",15)==15);close(fd);}
        }
    };
    const bool create_bad=test_mode=="collision" || test_mode=="second-create-failure" || test_mode=="create-expired" || test_mode=="create-wrong-boot" ||
        test_mode=="create-wrong-issuer" || test_mode=="create-active-authority" || test_mode=="create-live-module" || test_mode=="create-schema-mismatch" ||
        test_mode=="create-trigger" || test_mode=="create-link" || test_mode=="create-approval-replaced" || test_mode=="create-archive-failure" || test_mode=="create-credential-refusal" || test_mode=="create-schema-replaced" || test_mode=="create-schema-content-changed";
    if(create_bad){refuses([&]{factory.create(root+"/credential","qa","/fictional.sock");});check(test_drops==0);
        if(test_mode=="second-create-failure")check(test_creates==2 && test_resource_tables[0] && !test_resource_tables[1]);
        if(test_mode=="create-schema-mismatch" || test_mode=="create-trigger" || test_mode=="create-link" || test_mode=="create-archive-failure" || test_mode=="create-schema-replaced" || test_mode=="create-schema-content-changed")check(test_creates==2);
        else if(test_mode!="second-create-failure")check(test_creates==0);
        check(!dir->exists("qa.resources.complete.original"));return 0;}
    factory.create(root+"/credential","qa","/fictional.sock");check(test_creates==2 && dir->exists("qa.resources.complete.original"));
    if(test_mode=="diagnostic-timing")std::cerr<<"phase=create elapsed_ms="<<std::chrono::duration_cast<std::chrono::milliseconds>(Clock::now()-began).count()<<" queries="<<test_queries.size()<<std::endl;
    if(test_mode=="create"){check(test_drops==0);return 0;}
    if(test_mode=="create-reuse"){refuses([&]{factory.create(root+"/credential","qa","/fictional.sock");});check(test_creates==2);return 0;}
    cleanup=true;const auto created=dir->read("qa.resources.created.original",4096);auto c=parse(created.raw,"paymenter-source-qa-created-v1",CREATED_FIELDS);
    const std::string run(64,'a'),nonce(64,'1'),facts(64,'c');const auto now=now_seconds();
    const std::string intent="paymenter-source-qa-intent-v1\nrun="+run+"\ncase=F1\nnonce="+nonce+"\nfacts="+facts+"\npid=3\nstart=201\nissued="+std::to_string(now)+"\nexpires="+std::to_string(now+20)+"\n";
    if(test_mode!="cleanup-writer-without-intent")dir->create("intent.F1.original",intent);
    dir->create("writer.F1.original","paymenter-source-qa-writer-v1\nrun="+run+"\ncase=F1\nnonce="+nonce+"\nfacts="+(test_mode=="cleanup-bad-writer-binding"?std::string(64,'d'):facts)+"\nconnection=21\npid=3\nstart=201\nintent_sha256="+hash(intent)+"\n");
    if(test_mode=="cleanup-unknown-active-intent")dir->create("intent.v1","unregistered active intent\n");
    dir->create("revoked.v1","fictional fixture revocation\n");auto revoked=dir->read("revoked.v1",4096);auto creation_complete=dir->read("qa.resources.complete.original",4096);
    Fields approval{{"intent","retire-owned-qa"},{"run",test_mode=="cleanup-wrong-run"?std::string(64,'d'):run},{"boot","11111111-1111-1111-1111-111111111111"},
        {"issued",std::to_string(now-1)},{"expires",std::to_string(test_mode=="cleanup-expired"?now-1:now+25)},
        {"created_sha256",hash(created.raw)},{"created_identity",resource_identity(created)},{"revoked_sha256",hash(revoked.raw)},{"revoked_identity",resource_identity(revoked)},
        {"manager_pid","4"},{"manager_start","301"},{"complete_sha256",hash(creation_complete.raw)},{"complete_identity",resource_identity(creation_complete)}};
    dir->create("qa.cleanup.approval.original",encode_fields("paymenter-source-qa-cleanup-approval-v1",approval,CLEANUP_FIELDS));
    if(test_mode=="cleanup-no-revocation")check(unlink((root+"/revoked.v1").c_str())==0);
    if(test_mode=="cleanup-complete-copied")replace("qa.resources.complete.original");
    if(test_mode=="cleanup-copied-schema")replace("qa.schema.business.original");
    if(test_mode=="cleanup-changed-receipt")replace("qa.resources.created.original");
    if(test_mode=="cleanup-changed-registry")replace("registry.original");
    if(test_mode=="cleanup-replaced-revocation")replace("revoked.v1");
    if(test_mode=="cleanup-archive-preexists")dir->create("qa.retirement.original","earlier retained failure\n");
    if(test_mode=="cleanup-start-replaced")test_query_hook=[&](const std::string&){if(!changed){changed=true;replace("qa.cleanup.started.original");}};
    const auto before=test_connects;
    if(test_mode=="cleanup-fork" || test_mode=="cleanup-thread") {
        NativeQaResourceRetirementGuard g(dir,hooks);
        if(test_mode=="cleanup-fork"){pid_t child=fork();check(child>=0);if(child==0){try{g.preflight();_exit(1);}catch(const std::exception&){_exit(0);}}int status=0;check(waitpid(child,&status,0)==child && WIFEXITED(status) && WEXITSTATUS(status)==0);}
        else {bool held=false;std::thread t([&]{try{g.preflight();}catch(const std::exception&){held=true;}});t.join();check(held);}
        check(test_connects==before);return 0;
    }
    bool good=test_mode=="cleanup" || test_mode=="cleanup-reuse" || test_mode=="diagnostic-timing";
    if(good){auto result=factory.retire(root+"/credential","qa","/fictional.sock");check(result.complete && test_drops==1 && dir->exists("retired.v1"));
        if(test_mode=="diagnostic-timing")std::cerr<<"phase=cleanup elapsed_ms="<<std::chrono::duration_cast<std::chrono::milliseconds>(Clock::now()-began).count()<<" queries="<<test_queries.size()<<std::endl;
        if(test_mode=="cleanup-reuse")refuses([&]{factory.retire(root+"/credential","qa","/fictional.sock");});
    }else {refuses([&]{factory.retire(root+"/credential","qa","/fictional.sock");});
        if(test_mode=="cleanup-start-replaced")check(test_drops==0 && test_connects==before+1);
        else if(test_mode!="cleanup-partial-drop")check(test_drops==0 && test_connects==before);
        else check(test_drops==1 && dir->exists("qa.cleanup.failure.original") && !dir->exists("retired.v1"));}
 }catch(const std::exception& e){std::cerr<<e.what()<<'\n';return 1;}
}
