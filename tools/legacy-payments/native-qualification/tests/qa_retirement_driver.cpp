#include "../native/qa_retirement_archive.cpp"
#include <iostream>
using namespace paymenter_qa;
static void check(bool v){if(!v)throw std::runtime_error("test assertion");}
const std::string RUN(64,'a'),FACTS(64,'c');
struct TestGuard:QaRetirementGuard {
    std::string mode;mutable unsigned calls=0;
    void current()const override {++calls;if(mode=="guard-held" || (mode=="guard-changed" && calls>3))throw QaRetirementError("guard-held");}
};
struct TestDB:QaRetirementDatabase {
    std::string mode;ReadRows data;unsigned erased=0,dropped=0,locks=0,unlocks=0;bool locked=false;
    void lock_qa_tables()override {++locks;locked=true;if(mode=="lock-failure")throw QaRetirementError("lock");}
    std::array<std::string,2> definitions()override {
        check(locked);
        return {{mode=="changed-schema" || (mode=="schema-after-delete" && erased)?"unexpected schema":"business definition","journal definition"}};
    }
    ReadRows all_qa_rows()override {check(locked);return data;}
    void erase_case(const std::string& run,const std::string& id,const std::string& facts)override {
        check(locked && run==RUN && id=="F1" && facts==FACTS);++erased;
        if(mode=="delete-failure")throw QaRetirementError("delete");
        if(mode!="remaining-row")data.clear();
    }
    void drop_empty_qa_tables()override {
        check(locked && data.empty());++dropped;
        if(mode=="drop-failure")throw QaRetirementError("drop");
    }
    bool qa_tables_absent()override {return dropped==1 && mode!="drop-not-absent";}
    void unlock_qa_tables()override {++unlocks;locked=false;if(mode=="unlock-failure")throw QaRetirementError("unlock");}
};
struct FaultArchive:QaRetirementArchive {
    ProtectedQaArchive& real;std::string mode;unsigned* erased=nullptr;
    FaultArchive(ProtectedQaArchive& a,std::string m):real(a),mode(m){}
    void retain(const std::string& raw)override {if(mode=="archive-failure")throw QaRetirementError("archive");real.retain(raw);}
    std::string reload()override {return mode=="archive-corrupt" || (mode=="archive-after-delete" && erased && *erased)?"corrupt archive":real.reload();}
};
int main(int argc,char**argv) {
 try {
    check(argc==3);std::string mode=argv[1],root=argv[2];
    auto dir=std::make_shared<PrivateDirectory>(root,Owner{getuid(),getgid()});ProtectedQaArchive durable(dir);FaultArchive archive(durable,mode);
    QaRetirementRegistry registry{RUN,{{"F1",FACTS}},{{"business definition","journal definition"}}};
    if(mode=="invalid-run")registry.run="' OR 1=1";
    if(mode=="invalid-case")registry.cases[0].id="M1";
    if(mode=="duplicate-case")registry.cases.push_back(registry.cases[0]);
    if(mode=="invalid-facts")registry.cases[0].facts="bad";
    TestGuard guard;guard.mode=mode;TestDB db;db.mode=mode;
    archive.erased=&db.erased;
    if(mode!="empty")db.data={{"business",RUN,"F1","0.0100",FACTS},{"journal",RUN,"F1","F1",FACTS}};
    if(mode=="split-owned")db.data.resize(1);
    if(mode=="foreign-run")db.data[0][1]=std::string(64,'e');
    if(mode=="unknown-case")db.data[0][2]="F5";
    if(mode=="wrong-facts")db.data[0][4]=std::string(64,'d');
    if(mode=="wrong-amount")db.data[0][3]="2.0000";
    if(mode=="duplicate-row")db.data.push_back(db.data[0]);
    if(mode=="archive-replay")dir->create("qa.retirement.original","previous original\n");
    auto original=db.data;
    auto result=retire_qa_tables(registry,guard,db,archive);
    if(mode=="invalid-run" || mode=="invalid-case" || mode=="duplicate-case" || mode=="invalid-facts") {
        check(!result.complete && db.locks==0 && db.erased==0 && db.dropped==0);return 0;
    }
    bool good=mode=="complete" || mode=="empty" || mode=="split-owned";
    check(result.complete==good);
    if(good){check(result.archived && db.data.empty() && db.dropped==1 && db.unlocks==1 && !db.locked);
        check(durable.reload().find("run="+RUN+"\n")!=std::string::npos);
        check(durable.reload().find("business_schema_hex=627573696e65737320646566696e6974696f6e\n")!=std::string::npos);
        if(mode!="empty")check(durable.reload().find(",302e30313030,")!=std::string::npos);
        return 0;}
    check(!result.blocker.empty());
    bool before_delete=mode=="foreign-run" || mode=="unknown-case" || mode=="wrong-facts" || mode=="wrong-amount" ||
        mode=="duplicate-row" || mode=="changed-schema" || mode=="archive-failure" || mode=="archive-corrupt" || mode=="guard-held" ||
        mode=="guard-changed" || mode=="lock-failure" || mode=="archive-replay";
    if(before_delete)check(db.erased==0 && db.dropped==0 && db.data==original);
    if(mode=="remaining-row" || mode=="schema-after-delete" || mode=="delete-failure" || mode=="archive-after-delete")check(db.dropped==0);
    if(mode!="guard-held")check(db.unlocks==1);
    if(mode=="foreign-run" || mode=="changed-schema" || mode=="drop-failure")check(result.archived);
 }catch(const std::exception&e){std::cerr<<e.what()<<'\n';return 1;}
}
