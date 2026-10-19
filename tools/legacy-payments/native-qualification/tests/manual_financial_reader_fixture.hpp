#pragma once
#include "../native/manual_financial_reader_core.hpp"
namespace fictional_manual_financial {
using namespace paymenter_qa;
const std::string F(64,'c'),RUN(64,'a');
ManualFinancialRequest request() {
    ManualFinancialRequest r;
    r.run=RUN;r.case_id=r.journal_case="M1";r.phase="after";r.writer="11";r.previous={"10"};
    r.fixture={"101","201","301","401","501","pmfixture",{{"1001","1002","1003","1004"}},{"801","802"},F};
    for(const auto& t:manual_financial_tables())r.schemas[t]="fictional schema "+t;
    return r;
}
ReadRows rows() {
    ReadRows r;auto add=[&](std::string kind,std::string id,std::string key,std::string value){r.push_back({kind,id,key,value,F});};
    for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"account","V:101"},{"project","V:301"},{"currency","V:401"},{"balance","V:0.0100"},{"creditlimit","V:0.0000"},{"allowpostpaid","V:off"},{"active","V:on"}})add("subaccount","201",p.first,p.second);
    for(const auto& id:{"1001","1002","1003","1004"}) {
        for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"subaccount","V:201"},{"paymethod","V:501"},{"currency","V:401"},{"status",std::string("V:")+(std::string(id)=="1001"?"4":"1")},{"subaccountamount","V:0.0100"},{"paymethodamount","V:0.0100"},{"usedamount","V:0.0000"},{"commissionamount","V:0.0000"},{"taxrate","V:0.0000"},{"taxamount","V:0.0000"},{"links","N|N|N|N|N|N|N|E|E|E|E|V:off|V:off|V:off"}})add("payment",id,p.first,p.second);
    }
    for(const auto& id:{"801","802"})for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"account","V:101"},{"enabled","V:off"},{"remoteid","N"},{"remotelevel","V:0"},{"level","V:29"}})add("user",id,p.first,p.second);
    for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"currency","V:401"},{"active","V:off"},{"recurring","V:off"},{"module","V:pmfixture"},{"commissionamount","V:0.0000"},{"commissionpercent","V:0"},{"minamount","V:0.0000"},{"maxamount","N"},{"links","N|E|N"}})add("method","501",p.first,p.second);
    for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"account","1"},{"subaccounts","1"},{"users","2"},{"payments","4"},{"method_payments","4"},{"project_memberships","1"},{"foreign_projects","0"},{"items","0"},{"billorders","0"},{"expenses","0"},{"lineitems","0"},{"recurring","0"},{"subscriptions","0"},{"reverse_links","0"},{"method_bindings","0"},{"method_parameters","0"},{"currency_usd","1"}})add("count","101",p.first,p.second);
    add("journal","1001","M1","M1");return r;
}
}
