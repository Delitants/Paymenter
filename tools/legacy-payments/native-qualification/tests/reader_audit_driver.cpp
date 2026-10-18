#include "../native/reader_core.hpp"
#include <iostream>
using namespace paymenter_qa;
class DB:public ReadDatabase {
public:std::string mode;unsigned queries=0;
    explicit DB(std::string m):mode(m){}
    void configure() override {}
    ReaderState state() override{return {mode=="reused"?"10":"12","READ-COMMITTED","qa","/fictional.sock","1",2};}
    ReadRows select(const std::string& sql) override {
        if(++queries==1)return {{"paymenter_qa_business_v1","InnoDB"},{"paymenter_qa_journal_v1","InnoDB"}};
        if(sql=="SHOW CREATE TABLE paymenter_qa_business_v1")return {{"paymenter_qa_business_v1",mode=="schema"?"changed":"business schema"}};
        if(sql=="SHOW CREATE TABLE paymenter_qa_journal_v1")return {{"paymenter_qa_journal_v1","journal schema"}};
        if(sql.find("information_schema.TRIGGERS")!=std::string::npos)return mode=="trigger"?ReadRows{{"present"}}:ReadRows{};
        if(sql.find("information_schema.KEY_COLUMN_USAGE")!=std::string::npos)return mode=="link"?ReadRows{{"present"}}:ReadRows{};
        if(sql!="SELECT 'business',run_id,case_id,CAST(amount AS CHAR),facts_hash FROM paymenter_qa_business_v1 UNION ALL SELECT 'journal',run_id,case_id,business_case_id,facts_hash FROM paymenter_qa_journal_v1 LIMIT 33")throw std::runtime_error("SQL");
        if(mode=="empty")return {};
        ReadRows rows;
        for(int i=1;i<=4;++i){auto id="F"+std::to_string(i);rows.push_back({"business",std::string(64,'a'),id,"0.0100",std::string(64,'b'+i)});rows.push_back({"journal",std::string(64,'a'),id,id,std::string(64,'b'+i)});}
        if(mode=="foreign")rows[0][1]=std::string(64,'f');
        if(mode=="F5")rows[0][2]="F5";
        if(mode=="wrong-facts")rows[0][4]=std::string(64,'f');
        if(mode=="duplicate")rows[1]=rows[0];
        if(mode=="oversize")rows.push_back(rows[0]);
        return rows;
    }
};
int main(int argc,char**argv){try{
    if(argc!=2)return 2;std::string mode=argv[1];DB db(mode);
    ReaderAuditRequest r{std::string(64,'a'),{{"F1",std::string(64,'c')},{"F2",std::string(64,'d')},{"F3",std::string(64,'e')},{"F4",std::string(64,'f')}},{"10"},{{"business schema","journal schema"}}};
    if(mode=="bad-registry")r.facts.erase("F4");
    if(mode=="history")r.previous={"10","10"};
    bool ok=false;
    try{CommittedRead reader(db,"qa","/fictional.sock",std::chrono::steady_clock::now()+std::chrono::seconds(2));auto out=reader.audit(r);ok=out.connection=="12" && out.rows.size()==(mode=="empty"?0:8);}catch(const ReaderError&){}
    if(ok!=(mode=="empty" || mode=="full"))return 3;
    return 0;
}catch(const std::exception&e){std::cerr<<e.what();return 1;}}
