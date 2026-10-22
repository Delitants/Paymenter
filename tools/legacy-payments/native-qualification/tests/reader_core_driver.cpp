#include "../native/reader_core.hpp"
#include <iostream>
using namespace paymenter_qa;
namespace {
const std::string RUN(64,'a'),FACTS(64,'c');
class ScriptedDB:public ReadDatabase {
public:
    std::string mode;unsigned snapshots=0,selects=0,configured=0;
    explicit ScriptedDB(std::string m):mode(m){}
    void configure() override {++configured;}
    ReaderState state() override {
        ++snapshots;
        return {mode=="same-writer"?"11":mode=="reused-reader"?"10":mode=="changed-connection" && snapshots>2?"13":"12",
            mode=="repeatable"?"REPEATABLE-READ":"READ-COMMITTED",mode=="wrong-schema"?"other":"qa",
            mode=="wrong-socket"?"/other.sock":"/fictional.sock",mode=="no-autocommit"?"0":"1",
            static_cast<unsigned>(mode=="no-autocommit"?0:mode=="dirty" || (mode=="active-before-configure" && snapshots==1)?3:2)};
    }
    ReadRows select(const std::string& sql) override {
        ++selects;
        if(selects==1) {
            if(sql!="SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('paymenter_qa_business_v1','paymenter_qa_journal_v1') ORDER BY TABLE_NAME")throw std::runtime_error("unexpected metadata SQL");
            if(mode=="missing-table")return {};
            return {{"paymenter_qa_business_v1",mode=="nontransactional-table"?"MyISAM":"InnoDB"},{"paymenter_qa_journal_v1","InnoDB"}};
        }
        const std::string expected="SELECT 'business',run_id,case_id,CAST(amount AS CHAR),facts_hash FROM paymenter_qa_business_v1 WHERE run_id='"+RUN+"' AND case_id='F1' UNION ALL SELECT 'journal',run_id,case_id,business_case_id,facts_hash FROM paymenter_qa_journal_v1 WHERE run_id='"+RUN+"' AND case_id='F1' LIMIT 3";
        if(sql!=expected)throw std::runtime_error("unexpected row SQL");
        if(mode=="query-failure")throw ReaderError("reader-query");
        if(mode=="absent")return {};
        ReadRows rows={{"business",mode=="wrong-row"?std::string(64,'b'):RUN,"F1",mode=="wrong-amount"?"0.0200":"0.0100",FACTS}};
        if(mode!="split")rows.push_back({"journal",RUN,"F1","F1",FACTS});
        if(mode=="duplicate")rows.push_back(rows[0]);
        return rows;
    }
};
}
int main(int argc,char**argv) {
    if(argc!=2)return 2;
    std::string mode=argv[1];ScriptedDB db(mode);
    ReaderRequest request={RUN,"F1",FACTS,"11",{"10"}};
    if(mode=="invalid-run")request.run="' OR 1=1";
    if(mode=="invalid-case")request.case_id="M1";
    if(mode=="invalid-facts")request.facts="invalid";
    if(mode=="invalid-writer")request.writer="011";
    if(mode=="duplicate-history")request.previous={"10","10"};
    auto end=std::chrono::steady_clock::now()+std::chrono::seconds(mode=="expired"?-1:2);
    try {
        CommittedRead reader(db,"qa","/fictional.sock",end);
        auto result=reader.observe(request);
        if(mode=="reused-session") {reader.observe(request);return 3;}
        if(mode!="pair" && mode!="absent" && mode!="split")return 4;
        size_t wanted=mode=="pair"?2:mode=="absent"?0:1;
        if(result.rows.size()!=wanted || result.connection!="12" || db.selects!=2 || db.configured!=1)return 5;
    } catch(const ReaderError&) {
        if(mode=="pair" || mode=="absent" || mode=="split")return 6;
        if(mode=="active-before-configure" && db.configured)return 7;
    } catch(const std::exception& e) {std::cerr<<e.what();return 8;}
}
