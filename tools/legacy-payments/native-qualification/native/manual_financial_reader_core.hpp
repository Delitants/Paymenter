// Read-only observations, never native financial or notification acceptance.
#pragma once
#include "reader_core.hpp"
#include <cstdint>
#include <limits>
#include <sstream>
namespace paymenter_qa {
struct ManualFinancialFixture {
    std::string account,subaccount,project,currency,method,module;
    std::array<std::string,4> payments;
    std::vector<std::string> users;
    std::string facts;
};
struct ManualFinancialRequest {
    ManualFinancialFixture fixture;
    std::string run,case_id,journal_case,phase,writer;
    std::vector<std::string> previous;
    std::map<std::string,std::string> schemas;
    ReadRows foreign_keys;
};
inline const std::vector<std::string>& manual_financial_tables() {
    static const std::vector<std::string> names={"account","account2project","affiliatereward","billorder","billorderitem","currency","expense","expense2payment","invoiceitem2payment","item","lineitem","payment","payment2payment","payment_upload2payment","paymenter_qa_journal_v1","paymethod","paymethod2company","paymethod2group","paymethod2project","paymethodparam","promotioncoupon2account","recurring","recurring_subscription","subaccount","user"};return names;
}
inline uint64_t manual_unsigned(const std::string& s,bool zero,uint64_t maximum) {
    read_guard(!s.empty() && s.size()<=20 && (s.size()==1 || s[0]!='0'),"manual-read-number");uint64_t n=0;
    for(char c:s){read_guard(c>='0' && c<='9' && n<=(maximum-static_cast<unsigned>(c-'0'))/10,"manual-read-number");n=n*10+static_cast<unsigned>(c-'0');}
    read_guard(zero || n>0,"manual-read-number");return n;
}
inline int64_t manual_financial_units(const std::string& s) {
    bool neg=!s.empty() && s[0]=='-';size_t start=neg?1:0,dot=s.find('.');
    read_guard(dot!=std::string::npos && dot>start && dot-start<=14 && s.size()-dot==5 && (dot-start==1 || s[start]!='0'),"manual-read-money");
    int64_t n=0;for(size_t i=start;i<s.size();++i){if(i==dot)continue;read_guard(s[i]>='0' && s[i]<='9',"manual-read-money");n=n*10+(s[i]-'0');}
    read_guard(!neg || n!=0,"manual-read-money");return neg?-n:n;
}
inline bool manual_identifier(const std::string& s,size_t max=64) {
    return !s.empty() && s.size()<=max && s[0]!='0' && s.find_first_not_of("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_")==std::string::npos;
}
inline void manual_financial_validate_request(const ManualFinancialRequest& r) {
    const auto& f=r.fixture;read_guard(reader_hex(r.run) && reader_hex(f.facts),"manual-read-digest");
    for(const auto& id:{f.account,f.subaccount,f.project,f.currency,f.method})manual_unsigned(id,false,2147483647);
    read_guard(manual_identifier(f.module,32),"manual-read-module");
    std::set<std::string> payments;for(const auto& id:f.payments){manual_unsigned(id,false,2147483647);read_guard(payments.insert(id).second,"manual-read-payments");}
    read_guard(!f.users.empty() && f.users.size()<=8,"manual-read-users");uint64_t last=0;
    for(const auto& id:f.users){auto n=manual_unsigned(id,false,2147483647);read_guard(n>last,"manual-read-users");last=n;}
    read_guard(r.case_id=="M1" || r.case_id=="M2" || r.case_id=="M3" || r.case_id=="M4" || r.case_id=="M5","manual-read-case");
    read_guard(r.journal_case=="M1" || r.journal_case=="M2" || r.journal_case=="M3" || r.journal_case=="M4","manual-read-case");
    read_guard(r.case_id=="M5" || r.case_id==r.journal_case,"manual-read-case");
    read_guard(r.phase=="before" || r.phase=="barrier" || r.phase=="after" || r.phase=="retired","manual-read-phase");
    manual_unsigned(r.writer,false,std::numeric_limits<uint64_t>::max());read_guard(r.previous.size()<=64,"manual-read-history");std::set<std::string> ids;
    for(const auto& id:r.previous){manual_unsigned(id,false,std::numeric_limits<uint64_t>::max());read_guard(ids.insert(id).second,"manual-read-history");}
    read_guard(r.schemas.size()==manual_financial_tables().size(),"manual-read-schema");
    for(const auto& t:manual_financial_tables()){auto it=r.schemas.find(t);read_guard(it!=r.schemas.end() && !it->second.empty() && it->second.size()<=32768,"manual-read-schema");}
    read_guard(r.foreign_keys.size()<512,"manual-read-foreign-keys");
    std::set<std::vector<std::string>> keys;
    for(const auto& row:r.foreign_keys){read_guard(row.size()==5,"manual-read-foreign-keys");for(const auto& cell:row)read_guard(!cell.empty() && cell.size()<=129 && cell.find_first_not_of("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.")==std::string::npos,"manual-read-foreign-keys");read_guard(keys.insert(row).second,"manual-read-foreign-keys");read_guard(row[1]!="paymenter_qa_journal_v1" && row[3].find(".paymenter_qa_journal_v1")==std::string::npos,"manual-read-journal-link");}
}
inline std::string manual_sql_list(const std::vector<std::string>& v,bool quote=false) {
    std::string out;for(const auto& s:v){if(!out.empty())out+=",";out+=quote?"'"+s+"'":s;}return "("+out+")";
}
inline std::string manual_cell(const std::string& col) {
    return "CASE WHEN "+col+" IS NULL THEN 'N' WHEN CAST("+col+" AS CHAR)='' THEN 'E' ELSE CONCAT('V:',CAST("+col+" AS CHAR)) END";
}
inline std::string manual_presence(const std::string& col) {
    return "CASE WHEN "+col+" IS NULL THEN 'N' WHEN CAST("+col+" AS CHAR)='' THEN 'E' ELSE 'P' END";
}
inline std::string manual_link_cell(const std::string& table,const std::vector<std::string>& refs,const std::vector<std::string>& flags={}) {
    std::string out="CONCAT(";bool first=true;
    for(const auto& name:refs){if(!first)out+=",'|',";first=false;out+=manual_presence(table+"."+name);}
    for(const auto& name:flags){if(!first)out+=",'|',";first=false;out+=manual_cell(table+"."+name);}return out+")";
}
inline std::string manual_financial_query(const ManualFinancialRequest& r) {
    manual_financial_validate_request(r);const auto& f=r.fixture;std::vector<std::string> parts;
    auto field=[&](const std::string& kind,const std::string& table,const std::string& name,const std::string& expr,std::string where){
        if(kind=="subaccount")where+=" AND subaccount.account="+f.account;
        if(kind=="payment")where+=" AND payment.subaccount="+f.subaccount+" AND EXISTS (SELECT 1 FROM subaccount WHERE subaccount.id="+f.subaccount+" AND subaccount.account="+f.account+")";
        if(kind=="user")where+=" AND user.account="+f.account;
        parts.push_back("SELECT '"+kind+"',CAST("+table+".id AS CHAR),'"+name+"',"+expr+",'"+f.facts+"' FROM "+table+" WHERE "+where);};
    for(const auto& n:{"account","project","currency","balance","creditlimit","allowpostpaid","active"})field("subaccount","subaccount",n,manual_cell(std::string("subaccount.")+n),"subaccount.id="+f.subaccount);
    const auto pays=manual_sql_list(std::vector<std::string>(f.payments.begin(),f.payments.end()));
    for(const auto& n:{"subaccount","paymethod","currency","status","subaccountamount","paymethodamount","usedamount","commissionamount","taxrate","taxamount"})field("payment","payment",n,manual_cell(std::string("payment.")+n),"payment.id IN "+pays);
    field("payment","payment","links",manual_link_cell("payment",{"billorder","invoice","recurring","recurring_subscription","expense_commission","sender","recipient","externalid","xmlparams","recurring_action_on_fail","recurring_action_on_success"},{"refund","autogenerated","chargecommission"}),"payment.id IN "+pays);
    for(const auto& n:{"account","enabled","remoteid","remotelevel","level"})field("user","user",n,manual_cell(std::string("user.")+n),"user.id IN "+manual_sql_list(f.users));
    for(const auto& n:{"currency","active","recurring","module","commissionamount","commissionpercent","minamount","maxamount"})field("method","paymethod",n,manual_cell(std::string("paymethod.")+n),"paymethod.id="+f.method);
    field("method","paymethod","links",manual_link_cell("paymethod",{"profiletype","xmlparams","receipt_profiletype"}),"paymethod.id="+f.method);
    auto count=[&](const std::string& name,const std::string& sql){parts.push_back("SELECT 'count','"+f.account+"','"+name+"',CAST(("+sql+") AS CHAR),'"+f.facts+"'");};
    count("account","SELECT COUNT(*) FROM account WHERE id="+f.account);
    count("subaccounts","SELECT COUNT(*) FROM subaccount WHERE account="+f.account);
    count("users","SELECT COUNT(*) FROM user WHERE account="+f.account);
    count("payments","SELECT COUNT(*) FROM payment WHERE subaccount="+f.subaccount);
    count("method_payments","SELECT COUNT(*) FROM payment WHERE paymethod="+f.method);
    count("project_memberships","SELECT COUNT(*) FROM account2project WHERE account="+f.account);
    count("foreign_projects","SELECT COUNT(*) FROM account2project WHERE account="+f.account+" AND (project IS NULL OR project<>"+f.project+")");
    count("items","SELECT COUNT(*) FROM item WHERE account="+f.account);
    for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"billorders","billorder"},{"expenses","expense"},{"lineitems","lineitem"}})count(p.first,"SELECT COUNT(*) FROM "+p.second+" WHERE subaccount="+f.subaccount);
    count("recurring","SELECT COUNT(*) FROM recurring WHERE subaccount="+f.subaccount+" OR paymethod="+f.method+" OR recurring.init_payment IN "+pays);
    count("subscriptions","SELECT COUNT(*) FROM recurring_subscription WHERE subaccount="+f.subaccount+" OR paymethod="+f.method);
    std::vector<std::string> reverse;
    for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"affiliatereward","payment"},{"billorderitem","commission_for_payment"},{"expense2payment","payment"},{"invoiceitem2payment","payment"},{"payment2payment","payment_base"},{"payment2payment","payment_refund"},{"payment_upload2payment","payment"},{"promotioncoupon2account","payment"}})reverse.push_back("(SELECT COUNT(*) FROM "+p.first+" WHERE "+p.second+" IN "+pays+")");
    std::string sum;for(const auto& s:reverse){if(!sum.empty())sum+="+";sum+=s;}count("reverse_links","SELECT "+sum);
    count("method_bindings","SELECT (SELECT COUNT(*) FROM paymethod2company WHERE paymethod="+f.method+")+(SELECT COUNT(*) FROM paymethod2group WHERE paymethod="+f.method+")+(SELECT COUNT(*) FROM paymethod2project WHERE paymethod="+f.method+")");
    count("method_parameters","SELECT COUNT(*) FROM paymethodparam WHERE paymethod="+f.method);
    count("currency_usd","SELECT COUNT(*) FROM currency WHERE id="+f.currency+" AND iso='USD' AND active='on'");
    const auto& pay=f.payments[static_cast<size_t>(r.journal_case[1]-'1')];
    parts.push_back("SELECT 'journal','"+pay+"',case_id,business_case_id,facts_hash FROM paymenter_qa_journal_v1 WHERE run_id='"+r.run+"' AND case_id='"+r.journal_case+"'");
    std::string sql;for(const auto& p:parts){if(!sql.empty())sql+=" UNION ALL ";sql+=p;}sql+=" LIMIT 128";
    read_guard(sql.size()<=65536,"manual-read-query-bound");return sql;
}
inline void manual_financial_rows(const ManualFinancialRequest& r,const ReadRows& rows) {
    manual_financial_validate_request(r);read_guard(rows.size()<128,"manual-read-row-bound");const auto& f=r.fixture;
    using Key=std::vector<std::string>;std::map<Key,std::string> expected;
    auto bind=[&](std::string kind,std::string id,std::string name,std::string rule){expected[{kind,id,name}]=rule;};
    for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"account","V:"+f.account},{"project","V:"+f.project},{"currency","V:"+f.currency},{"balance","money"},{"creditlimit","money"},{"allowpostpaid","bool"},{"active","bool"}})bind("subaccount",f.subaccount,p.first,p.second);
    for(const auto& id:f.payments)for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"subaccount","V:"+f.subaccount},{"paymethod","V:"+f.method},{"currency","V:"+f.currency},{"status","status"},{"subaccountamount","V:0.0100"},{"paymethodamount","V:0.0100"},{"usedamount","money"},{"commissionamount","V:0.0000"},{"taxrate","money"},{"taxamount","money"},{"links","links"}})bind("payment",id,p.first,p.second);
    for(const auto& id:f.users)for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"account","V:"+f.account},{"enabled","V:off"},{"remoteid","N"},{"remotelevel","remotelevel"},{"level","clientlevel"}})bind("user",id,p.first,p.second);
    for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"currency","V:"+f.currency},{"active","V:off"},{"recurring","V:off"},{"module","V:"+f.module},{"commissionamount","V:0.0000"},{"commissionpercent","V:0"},{"minamount","minimum"},{"maxamount","maximum"},{"links","methodlinks"}})bind("method",f.method,p.first,p.second);
    for(const auto& p:std::vector<std::pair<std::string,std::string>>{{"account","1"},{"subaccounts","1"},{"users",std::to_string(f.users.size())},{"payments","4"},{"method_payments","4"},{"project_memberships","1"},{"foreign_projects","0"},{"items","0"},{"billorders","0"},{"expenses","0"},{"lineitems","0"},{"recurring","0"},{"subscriptions","0"},{"reverse_links","0"},{"method_bindings","0"},{"method_parameters","0"},{"currency_usd","1"}})bind("count",f.account,p.first,p.second);
    size_t bytes=0;bool journal=false;std::set<Key> seen;
    for(const auto& row:rows) {
        read_guard(row.size()==5,"manual-read-shape");for(const auto& cell:row){read_guard(!cell.empty() && cell.size()<=256,"manual-read-cell");bytes+=cell.size();for(unsigned char c:cell)read_guard(c>=32 && c<127,"manual-read-cell");}read_guard(bytes<=65536 && row[4]==f.facts,"manual-read-binding");
        Key key={row[0],row[1],row[2]};read_guard(seen.insert(key).second,"manual-read-duplicate");
        if(row[0]=="journal"){read_guard(!journal && row[1]==f.payments[static_cast<size_t>(r.journal_case[1]-'1')] && row[2]==r.journal_case && row[3]==r.journal_case,"manual-read-journal");journal=true;continue;}
        auto it=expected.find(key);read_guard(it!=expected.end(),"manual-read-foreign-row");const auto& rule=it->second;const auto& v=row[3];
        auto value=[&](){read_guard(v.compare(0,2,"V:")==0,"manual-read-value");return v.substr(2);};
        if(rule=="money")manual_financial_units(value());
        else if(rule=="bool")read_guard(v=="V:on" || v=="V:off","manual-read-bool");
        else if(rule=="remotelevel")read_guard(v=="N" || v=="V:0","manual-read-remote");
        else if(rule=="clientlevel")read_guard(manual_unsigned(value(),false,29)<=29,"manual-read-client");
        else if(rule=="status"){auto n=manual_unsigned(value(),false,100);read_guard(n<=9 || n==100,"manual-read-status");}
        else if(rule=="minimum")read_guard(v=="N" || (manual_financial_units(value())>=0 && manual_financial_units(value())<=100),"manual-read-limit");
        else if(rule=="maximum")read_guard(v=="N" || manual_financial_units(value())>=100,"manual-read-limit");
        else if(rule=="links" || rule=="methodlinks") {
            std::istringstream in(v);std::string part;std::vector<std::string> parts;while(std::getline(in,part,'|'))parts.push_back(part);
            read_guard(v.back()!='|' && parts.size()==(rule=="links"?14:3),"manual-read-links");
            for(size_t i=0;i<parts.size();++i) {
                if(rule=="methodlinks")read_guard(parts[i]=="N" || parts[i]=="E","manual-read-links");
                else if(i<7)read_guard(parts[i]=="N","manual-read-links");
                else if(i<11)read_guard(parts[i]=="N" || parts[i]=="E","manual-read-links");
                else read_guard(parts[i]=="V:off" || (i==13 && parts[i]=="V:on"),"manual-read-links");
            }
        } else read_guard(v==rule,"manual-read-value");
        expected.erase(it);
    }
    read_guard(expected.empty(),"manual-read-missing-rows");
}
class ManualFinancialRead {
    ReadDatabase& db_;std::string database_,socket_;std::chrono::steady_clock::time_point end_;bool consumed_=false;
    void deadline()const{read_guard(std::chrono::steady_clock::now()<end_,"manual-read-deadline");}
    ReaderState state(const std::string& id,bool configured){deadline();auto s=db_.state();deadline();manual_unsigned(s.connection,false,std::numeric_limits<uint64_t>::max());read_guard((id.empty() || s.connection==id) && s.database==database_ && s.socket==socket_ && !(s.server_status&1),"manual-read-session");if(configured)read_guard(s.isolation=="READ-COMMITTED" && s.autocommit=="1" && (s.server_status&2),"manual-read-isolation");return s;}
    ReadRows query(const std::string& sql,const std::string& id){deadline();auto rows=db_.select(sql);deadline();state(id,true);return rows;}
    void metadata(const ManualFinancialRequest& r,const std::string& id) {
        auto names=manual_sql_list(manual_financial_tables(),true);ReadRows wanted;for(const auto& t:manual_financial_tables())wanted.push_back({t,"InnoDB"});
        read_guard(query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "+names+" ORDER BY TABLE_NAME",id)==wanted,"manual-read-engines");
        read_guard(query("SELECT 'present' FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN "+names+" LIMIT 1",id).empty(),"manual-read-trigger");
        auto fk=query("SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,CONCAT(REFERENCED_TABLE_SCHEMA,'.',REFERENCED_TABLE_NAME),REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE (TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "+names+" AND REFERENCED_TABLE_NAME IS NOT NULL) OR (REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN "+names+") ORDER BY TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME LIMIT 512",id);
        auto expected=r.foreign_keys;std::sort(expected.begin(),expected.end());std::sort(fk.begin(),fk.end());read_guard(fk==expected,"manual-read-foreign-key-drift");
        for(const auto& t:manual_financial_tables())read_guard(query("SHOW CREATE TABLE "+t,id)==ReadRows{{t,r.schemas.at(t)}},"manual-read-schema-drift");
    }
public:
    ManualFinancialRead(ReadDatabase& db,std::string database,std::string socket,std::chrono::steady_clock::time_point end):db_(db),database_(std::move(database)),socket_(std::move(socket)),end_(end){}
    ReadResult observe(const ManualFinancialRequest& r) {
        manual_financial_validate_request(r);read_guard(!consumed_,"manual-read-reused");consumed_=true;
        auto s=state("",false);deadline();db_.configure();state(s.connection,true);read_guard(s.connection!=r.writer && std::find(r.previous.begin(),r.previous.end(),s.connection)==r.previous.end(),"manual-read-independent");
        metadata(r,s.connection);auto rows=query(manual_financial_query(r),s.connection);manual_financial_rows(r,rows);metadata(r,s.connection);return {s.connection,rows};
    }
};
}
