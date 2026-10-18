#include "../native/qa_retirement_mysql.cpp"
#include <iostream>
#include <sys/wait.h>
using namespace paymenter_qa;
static void check(bool ok){if(!ok)throw std::runtime_error("test assertion");}
struct TestGuard:QaRetirementGuard {
    void current()const override {
        if(test_mode=="guard-before-connect" || (test_mode=="guard-before-delete" && test_locked))throw QaRetirementError("test-guard-held");
    }
};
struct Archive:QaRetirementArchive {
    std::string raw;
    void retain(const std::string& v)override {check(raw.empty() && test_erases==0 && test_drops==0 && test_locked);raw=v;}
    std::string reload()override{return raw;}
};
int main(int argc,char**argv){
 try{
    check(argc==3);test_mode=argv[1];const std::string run(64,'a'),facts(64,'c');TestGuard guard;
    QaRetirementRegistry registry{run,{{"F1",facts}},{{"paymenter_qa_business_v1 definition","paymenter_qa_journal_v1 definition"}}};
    const bool early=test_mode=="guard-before-connect" || test_mode=="dirty-session" || test_mode=="wrong-database" || test_mode=="wrong-socket" || test_mode=="expired";
    std::unique_ptr<NativeQaRetirementDatabase> db;
    try {db.reset(new NativeQaRetirementDatabase("fictional-secret","qa","/fictional.sock",guard,registry,Clock::now()+std::chrono::seconds(test_mode=="expired"?-1:20)));}
    catch(const QaRetirementError&){check(early && test_locks==0 && test_erases==0);if(test_mode=="guard-before-connect" || test_mode=="expired")check(test_connects==0);return 0;}
    check(!early);
    if(test_mode=="after-release"){
        db->lock_qa_tables();test_rows=false;db->drop_empty_qa_tables();check(db->qa_tables_absent());db->unlock_qa_tables();
        const auto before=test_queries.size();try{db->qa_tables_absent();return 8;}catch(const QaRetirementError&){check(test_queries.size()==before);return 0;}
    }
    if(test_mode=="query-before-lock"){
        const auto before=test_queries.size();try{db->all_qa_rows();return 3;}catch(const QaRetirementError&){check(test_queries.size()==before);return 0;}}
    if(test_mode=="thread-refused"){
        const auto before=test_queries.size();bool refused=false;
        std::thread other([&]{try{db->lock_qa_tables();}catch(const QaRetirementError&){refused=true;}});other.join();
        check(refused && test_queries.size()==before);return 0;
    }
    if(test_mode=="changed-delete-schema" || test_mode=="changed-drop-schema"){
        db->lock_qa_tables();
        try{if(test_mode=="changed-delete-schema")db->erase_case(run,"F1",facts);else{test_rows=false;db->drop_empty_qa_tables();}return 7;}
        catch(const QaRetirementError&){check(test_erases==0 && test_drops==0);db->unlock_qa_tables();return 0;}
    }
    if(test_mode=="fork-refused" || test_mode=="fork-destructor"){
        pid_t child=fork();check(child>=0);if(child==0){try{db->lock_qa_tables();_exit(1);}catch(const QaRetirementError&){db.reset();_exit(test_closes==0?0:2);}}
        int status=0;check(waitpid(child,&status,0)==child && WIFEXITED(status) && WEXITSTATUS(status)==0);return 0;}
    if(test_mode=="lock-reuse" || test_mode=="invalid-run" || test_mode=="invalid-case" || test_mode=="invalid-facts" || test_mode=="foreign-run" || test_mode=="unregistered-case" || test_mode=="foreign-facts"){
        db->lock_qa_tables();auto before=test_queries.size();
        try {if(test_mode=="lock-reuse")db->lock_qa_tables();
            else db->erase_case(test_mode=="invalid-run"?"x' OR 1=1":test_mode=="foreign-run"?std::string(64,'f'):run,test_mode=="invalid-case"?"F1,F2":test_mode=="unregistered-case"?"F2":"F1",test_mode=="invalid-facts"?"bad":test_mode=="foreign-facts"?std::string(64,'f'):facts);
            return 4;
        }catch(const QaRetirementError&){check(test_queries.size()==before && test_erases==0);db->unlock_qa_tables();return 0;}
    }
    Archive memory;std::unique_ptr<ProtectedQaArchive> durable;
    if(test_mode=="complete-durable")durable.reset(new ProtectedQaArchive(std::make_shared<PrivateDirectory>(argv[2],Owner{getuid(),getgid()})));
    QaRetirementArchive& archive=durable?static_cast<QaRetirementArchive&>(*durable):static_cast<QaRetirementArchive&>(memory);
    auto result=retire_qa_tables(registry,guard,*db,archive);
    const bool good=test_mode=="complete" || test_mode=="complete-durable" || test_mode=="metadata-deadline";
    check(result.complete==good);
    if(good){check(test_erases==2 && test_commits==1 && test_drops==1 && !test_locked && test_closes==1 && result.archived && !archive.reload().empty());}
    else {
        check(!result.blocker.empty());
        if(test_mode=="delete-second-failure" || test_mode=="commit-failure")check(test_erases==2 && test_drops==0 && result.erase_attempts==1 && result.erased_cases==0 && test_closes==1);
        else if(test_mode=="remaining-rows")check(test_drops==0);
        else if(test_mode=="drop-failure" || test_mode=="drop-not-absent")check(test_drops==1);
        else check(test_erases==0 && test_drops==0);
        if(test_mode=="trigger" || test_mode=="incoming-fk" || test_mode=="outgoing-fk" || test_mode=="wrong-engine" || test_mode=="view")check(test_locks==0);
    }
    for(const auto& q:test_queries){check(q.find("START TRANSACTION")==std::string::npos && q.find("IF EXISTS")==std::string::npos && q.find("CASCADE")==std::string::npos);}
 }catch(const std::exception& e){std::cerr<<e.what()<<'\n';return 1;}
}
