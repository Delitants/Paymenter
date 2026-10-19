#include "../native/manual_financial_reader_v2_core.hpp"
#include <iostream>
#include "manual_financial_reader_fixture.hpp"
using namespace paymenter_qa;
namespace {
using namespace fictional_manual_financial;
void set(ReadRows& r,const std::string& kind,const std::string& id,const std::string& field,const std::string& value) {
    for(auto& v:r)if(v[0]==kind && v[1]==id && v[2]==field){v[3]=value;return;}throw std::runtime_error("test field");
}
class DB:public ReadDatabase {
public:
    std::string mode;ManualFinancialRequest wanted;ReadRows data;unsigned configs=0,states=0,observations=0;
    DB(std::string m,ManualFinancialRequest r):mode(m),wanted(r),data(rows()) {
        data.erase(std::remove_if(data.begin(),data.end(),[](const std::vector<std::string>& x){return x[0]=="count" && x[2]=="method_bindings";}),data.end());
        for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"method_company","0"},{"method_group","0"},{"method_project","1"},{"method_foreign_project","0"}})
            data.push_back({"count","101",p.first,p.second,F});
        data.push_back({"method","501","allowrefund","V:off",F});
        set(data,"method","501","links","N|X:authored-empty-doc-v0|N");
    }
    void configure()override{++configs;}
    ReaderState state()override {
        ++states;return {mode=="same-writer"?"11":mode=="reused-reader"?"10":mode=="connection-drift" && states>2?"13":"12",mode=="repeatable"?"REPEATABLE-READ":"READ-COMMITTED",mode=="database"?"other":"qa",mode=="socket"?"/other.sock":"/fictional.sock",mode=="autocommit"?"0":"1",static_cast<unsigned>(mode=="transaction" && states==1?3:mode=="autocommit"?0:2)};
    }
    ReadRows select(const std::string& sql)override {
        if(sql.find("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES")==0) {
            ReadRows out;for(const auto& t:manual_financial_tables())out.push_back({t,mode=="myisam" && t=="payment"?"MyISAM":"InnoDB"});return out;
        }
        if(sql.find("SHOW CREATE TABLE ")==0) {auto t=sql.substr(18);return {{t,mode=="schema-drift" && t=="payment"?"changed":wanted.schemas.at(t)}};}
        if(sql.find("information_schema.TRIGGERS")!=std::string::npos)return mode=="trigger"?ReadRows{{"present"}}:ReadRows{};
        if(sql.find("information_schema.KEY_COLUMN_USAGE")!=std::string::npos){if(mode=="fk-query-bound" && sql.find(" LIMIT 512")==std::string::npos)throw ReaderError("unbounded metadata");return mode=="foreign-key-drift"?ReadRows{{"qa","new_table","p","qa.payment","id"}}:ReadRows{};}
        if(sql.find("SELECT 'subaccount'")==0) {
            if(++observations>1)throw std::runtime_error("sequential financial read views");
            if(sql.find("recurring.init_payment")==std::string::npos || sql.find("payment.expense_commission")==std::string::npos || sql.find("payment.recurring_subscription")==std::string::npos)throw std::runtime_error("missing physical link");
            if(mode=="query-error")throw ReaderError("fictional query failure");
            return data;
        }
        throw std::runtime_error("unexpected SQL");
    }
};
}
int main(int argc,char**argv) {try {
    if(argc!=2)return 2;std::string mode=argv[1];auto r=request();
    ManualFinancialV2Request v2{r,"paymenter-manual-financial-offline-v2",ManualV2Profile::AuthoredEmptyDocV0};
    if(mode=="emit-query"){std::cout<<manual_financial_v2_query(v2);return 0;}
    if(mode=="validate-input") {ReadRows data;std::string line;while(std::getline(std::cin,line)){std::istringstream in(line);std::vector<std::string> cells;std::string cell;while(std::getline(in,cell,'\t'))cells.push_back(cell);data.push_back(cells);}try{manual_financial_v2_rows(v2,data);return 0;}catch(const ReaderError&){return 1;}}
    if(mode.find("money-reject:")==0){try{manual_financial_units(mode.substr(13));return 22;}catch(const ReaderError&){return 0;}}
    if(mode=="money") {
        if(manual_financial_units("-99999999999999.9999")!=-999999999999999999LL || manual_financial_units("0.0100")!=100)return 20;return 0;
    }
    if(mode=="bad-id")r.fixture.account="0";
    if(mode=="sql-id")r.fixture.account="101 OR 1=1";
    if(mode=="bad-method")r.fixture.method="0";
    if(mode=="duplicate-payment")r.fixture.payments[1]=r.fixture.payments[0];
    if(mode=="duplicate-user")r.fixture.users[1]=r.fixture.users[0];
    if(mode=="unordered-users")r.fixture.users={"802","801"};
    if(mode=="bad-case")r.case_id="F1";
    if(mode=="bad-phase")r.phase="anything";
    if(mode=="bad-run")r.run="'";
    if(mode=="bad-facts")r.fixture.facts="x";
    if(mode=="bad-module")r.fixture.module="pmfixture'";
    if(mode=="duplicate-history")r.previous={"10","10"};
    if(mode=="bad-writer")r.writer="18446744073709551616";
    if(mode=="M5")r.case_id="M5";
    if(mode=="wrong-journal-case")r.journal_case="M2";
    if(mode=="missing-schema")r.schemas.erase("payment");
    v2.base=r;
    if(mode=="v1-version")v2.protocol="paymenter-manual-financial-read-v1";
    if(mode=="unknown-version")v2.protocol="unknown";
    if(mode=="unknown-profile")v2.profile=static_cast<ManualV2Profile>(99);
    DB db(mode,r);
    if(mode=="absent-journal")db.data.erase(std::remove_if(db.data.begin(),db.data.end(),[](const std::vector<std::string>& x){return x[0]=="journal";}),db.data.end());
    if(mode=="duplicate-row")db.data.push_back(db.data[0]);
    if(mode=="missing-row")db.data.erase(db.data.begin());
    if(mode=="foreign-row")db.data[0][1]="999";
    if(mode=="wrong-facts")db.data[0][4]=std::string(64,'d');
    if(mode=="wrong-owner")set(db.data,"payment","1001","subaccount","V:999");
    if(mode=="wrong-currency")set(db.data,"payment","1001","currency","V:999");
    if(mode=="wrong-method")set(db.data,"payment","1001","paymethod","V:999");
    if(mode=="extra-user")set(db.data,"count","101","users","3");
    if(mode=="extra-payment")set(db.data,"count","101","payments","5");
    if(mode=="enabled-user")set(db.data,"user","801","enabled","V:on");
    if(mode=="remote-user")set(db.data,"user","801","remoteid","V:0");
    if(mode=="staff-user")set(db.data,"user","801","level","V:30");
    if(mode=="linked")set(db.data,"count","101","reverse_links","1");
    if(mode=="recurring")set(db.data,"count","101","recurring","1");
    if(mode=="active-method")set(db.data,"method","501","active","V:on");
    if(mode=="fee")set(db.data,"method","501","commissionamount","V:0.0100");
    if(mode=="null-amount")set(db.data,"payment","1001","subaccountamount","N");
    if(mode=="empty-ref")set(db.data,"payment","1001","links","E|N|N|N|N|N|N|E|E|E|E|V:off|V:off|V:off");
    if(mode=="zero-ref")set(db.data,"payment","1001","links","P|N|N|N|N|N|N|E|E|E|E|V:off|V:off|V:off");
    if(mode=="negative-balance")set(db.data,"subaccount","201","balance","V:-0.0100");
    if(mode=="surviving-credit")db.data.erase(std::remove_if(db.data.begin(),db.data.end(),[](const std::vector<std::string>& x){return x[0]=="journal";}),db.data.end());
    if(mode=="zero-credit-journal")set(db.data,"subaccount","201","balance","V:0.0000");
    if(mode=="negative-zero")set(db.data,"subaccount","201","balance","V:-0.0000");
    if(mode=="overflow")set(db.data,"subaccount","201","balance","V:100000000000000.0000");
    if(mode=="exponent")set(db.data,"subaccount","201","balance","V:1e-2");
    if(mode=="cell-bound")db.data[0][3]=std::string(257,'x');
    if(mode=="row-bound")while(db.data.size()<128)db.data.push_back(db.data[0]);
    if(mode=="duplicate-journal")for(const auto& row:db.data)if(row[0]=="journal"){auto copy=row;db.data.push_back(copy);break;}
    if(mode=="refund")set(db.data,"method","501","allowrefund","V:on");
    if(mode=="foreign-project")set(db.data,"count","101","method_foreign_project","1");
    if(mode=="missing-project")set(db.data,"count","101","method_project","0");
    if(mode=="xml-presence")set(db.data,"method","501","links","N|P|N");
    bool accepted=false;
    try {
        ManualFinancialV2Read reader(db,"qa","/fictional.sock",std::chrono::steady_clock::now()+std::chrono::seconds(mode=="expired"?-1:5));
        auto out=reader.observe(v2);accepted=true;
        if(mode=="reuse"){reader.observe(v2);return 12;}
        if(out.connection!="12" || db.observations!=1 || out.rows!=db.data)return 13;
        if(mode=="negative-balance" && out.rows[3][3]!="V:-0.0100")return 14;
    } catch(const ReaderError& e) {if((mode=="v1-version" || mode=="unknown-version" || mode=="unknown-profile") && (db.configs || db.states || db.observations))return 30;if(mode=="transaction" && db.configs)return 15;if(mode=="reuse"){if(std::string(e.what())!="manual-read-reused")return 17;accepted=false;}}
    const bool wanted=mode=="valid" || mode=="fk-query-bound" || mode=="negative-balance" || mode=="absent-journal" || mode=="surviving-credit" || mode=="zero-credit-journal" || mode=="M5";
    return accepted==wanted?0:16;
} catch(const std::exception& e){std::cerr<<e.what();return 1;} }
