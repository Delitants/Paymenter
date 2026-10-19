// No DB/rollback emulation. Only dispatch, guard, journal-call and lifetime facts.
#include "manual_probe.hpp"
#include "manual_context.hpp"
#include <api/action.h>
#include <billmgr/util/internal/callback_internal.h>
#include <mgr/mgrdb_struct.h>
#include <mgr/mgrerr.h>
#include <functional>
#include <iostream>
#include <sys/wait.h>
#include <unistd.h>
std::shared_ptr<mgr_db::Cache> db;
using namespace paymenter_qa::manual;
namespace {
void check(bool v) {if(!v)throw std::runtime_error("test assertion");}
class NativeFailure:public std::runtime_error {public:NativeFailure():std::runtime_error("original native error") {}};
std::string scenario;
int dispatches=0,unsafe_calls=0,balance=0,inspections=0;
std::map<int,int> statuses={{101,1},{102,1},{103,1},{104,1}};
const isp_api::Session* expected_session=nullptr;
isp_api::Session* mutable_session=nullptr;
bool short_expiry=false;
Fixture fixture() {return {"11","12","13","14","USD","0.0100",{{"101","102","103","104"}},{"21"},std::string(64,'f')};}
Snapshot snapshot(int id) {
    Snapshot s;s.payment_id=std::to_string(id);s.account_id="11";s.subaccount_id="12";
    s.currency_id="13";s.method_id="14";s.currency_code="USD";s.amount="0.0100";
    s.balance=balance==0?"0.0000":balance==100?"0.0100":"0.0200";
    s.users={"21"};s.status=statuses.at(id);s.users_disabled=s.direct_isolated=s.queue_isolated=s.provider_isolated=true;
    return s;
}
void emit(int old_status,int new_status,int id) {callback::Store<event::PaymentStatusChanged>().Fire(old_status,new_status,id);}
struct TestAuthority:Authority {
    bool active=true;mutable int records=0;mutable int grants=0;
    std::string operation_id()const override {return std::string(64,'a');}
    Grant authorize(const Request& r)const override {
        ++grants;auto expected=r;expected.actor="fictional-admin";expected.run_id=std::string(64,'a');
        if(scenario=="wrong-nonce")expected.nonce=std::string(64,'b');
        return {expected,fixture(),phase_for(r.case_id),Clock::now()+(short_expiry?std::chrono::milliseconds(10):std::chrono::milliseconds(60000))};
    }
    bool current(const Grant&)const override {
        if(scenario=="expiry-during-read")std::this_thread::sleep_for(std::chrono::milliseconds(20));
        return active;
    }
    bool owns(int p)const noexcept override {return p>=101 && p<=104;}
    void record(const Request&,const Grant&,Checkpoint)const override {++records;}
    void record_connection(const Request&,const Grant&,const std::string& id)const override {check(id=="71");++records;}
    FirstResult first_result(const Grant&)const override {
        auto s=snapshot(104);s.status=4;s.balance="0.0100";
        if(scenario=="duplicate-baseline-mismatch")s.balance="0.0200";
        return {s,std::string(64,'d')}; // Authored value, not a native reader receipt.
    }
};
std::shared_ptr<TestAuthority> authority;
class OriginalAction:public isp_api::Action {
public:OriginalAction():Action("payment.setpaid",29){}
void Execute(isp_api::Session&)const override {throw std::runtime_error("original router double is never directly invoked");}
};
const isp_api::Action& own_action() {
    for(auto* a:isp_api::test_actions())if(a->name()=="paymenter.qa.manual")return *a;
    throw std::runtime_error("manual action missing");
}
void params(isp_api::Session& s,const std::string& id="M1",const std::string& slot="single") {
    s.params={{"qa_run",std::string(64,'a')},{"qa_case",id},{"qa_payment",id=="M1"?"101":id=="M2"?"102":id=="M3"?"103":"104"},{"qa_slot",slot},{"out","xml"}};
    char n=id=="M1"?'b':id=="M2"?'c':id=="M3"?'d':slot=="first"?'e':'f';
    s.params["qa_nonce"]=std::string(64,n);
}
std::string invoke(isp_api::Session& s) {
    expected_session=&s;mutable_session=&s;
    try {own_action().Execute(s);return "";}
    catch(const mgr_err::Error& e) {return e.what();}
}
struct Access:NativeAccess {
    Snapshot inspect(const Fixture&,int id,mgr_db::Cache& cache)override {
        check(&cache==db.get());++inspections;auto s=snapshot(id);
        if(scenario=="enabled-user")s.users_disabled=false;
        if(scenario=="direct-notifier")s.direct_isolated=false;
        if(scenario=="queue-not-isolated")s.queue_isolated=false;
        if(scenario=="provider-link")s.provider_isolated=false;
        if(scenario=="linked-service")s.linked=true;
        if(scenario=="wrong-native-owner")s.account_id="99";
        if(scenario=="wrong-native-amount")s.amount="0.0200";
        if(scenario=="wrong-native-currency")s.currency_code="EUR";
        if(scenario=="revoked-after-read" && inspections==2)authority->active=false;
        if(scenario=="nested-inspect-caught" && inspections==1) {
            check(!invoke(*mutable_session).empty());
        }
        if(scenario=="nested-callback-caught" && inspections==2) {
            bool refused=false;try {emit(1,4,id);}catch(const mgr_err::Error&){refused=true;}
            check(refused);
        }
        return s;
    }
};
}
namespace isp_api {
Xml InternalCall(const std::string&,const std::string&) {++unsafe_calls;throw std::runtime_error("unbound internal call");}
Authen Authenticate() {++unsafe_calls;throw std::runtime_error("unbound authenticate");}
Xml InternalCall(const Session& s,const std::string& function,const std::string& values) {
    ++dispatches;check(&s==expected_session && &s.auth==&expected_session->auth);
    check(function=="payment.setpaid" && values=="elid="+s.Param("qa_payment"));
    int id=std::stoi(s.Param("qa_payment"));
    if(scenario=="original-error" || (scenario=="same-thread-after-native-error" && dispatches==1))throw NativeFailure();
    if(statuses[id]==4) {
        if(scenario=="duplicate-native-error")throw NativeFailure();
        if(scenario=="duplicate-callback-noop")emit(4,4,id);
        return Xml("<doc><native-duplicate/></doc>");
    }
    statuses[id]=scenario=="wrong-status"?3:4;balance+=scenario=="wrong-delta"?200:100;
    if(scenario=="native-cache-change")db=std::make_shared<mgr_db::Cache>();
    if(scenario=="native-connection-change")db->connection=1;
    if(scenario=="native-server-change")db->server_id="72";
    if(scenario=="native-actor-change")mutable_session->auth.actor="other-admin";
    if(scenario=="native-role-change")mutable_session->auth.role=0;
    if(scenario=="native-revocation")authority->active=false;
    if(scenario=="preliminary-commit")check(ManualScope::current()!=nullptr);
    if(scenario!="missing-callback")emit(1,statuses[id],id);
    if(scenario=="double-callback")emit(1,4,id);
    return Xml("<doc><native-ok/></doc>");
}
}
int main(int argc,char** argv) {
    try {
        check(argc==2);scenario=argv[1];authority=std::make_shared<TestAuthority>();auto access=std::make_shared<Access>();
        if(scenario!="missing-action")new OriginalAction();
        db=std::make_shared<mgr_db::Cache>();
        if(scenario=="missing-authority" || scenario=="missing-access" || scenario=="missing-action") {
            bool failed=false;try {paymenter_qa::RegisterManualProbe(scenario=="missing-authority"?nullptr:authority,scenario=="missing-access"?nullptr:access);}catch(const paymenter_qa::ProbeError&){failed=true;}
            check(failed && callback::Store<event::PaymentStatusChanged>().values.empty() && dispatches==0 && mgr_db::calls().empty());return 0;
        }
        if(scenario=="store-registration-failure")callback::test_add_failure()=true;
        if(scenario=="action-registration-failure")isp_api::test_action_failure()=true;
        if(scenario=="registration-reentry")callback::test_add_hook()=[&] {try {paymenter_qa::RegisterManualProbe(authority,access);}catch(const paymenter_qa::ProbeError&) {}};
        if(scenario=="action-registration-reentry")isp_api::test_action_hook()=[&] {try {paymenter_qa::RegisterManualProbe(authority,access);}catch(const paymenter_qa::ProbeError&) {}};
        if(scenario=="store-registration-failure" || scenario=="action-registration-failure" || scenario=="registration-reentry" || scenario=="action-registration-reentry") {
            std::weak_ptr<Access> retained=access;bool failed=false;
            try {paymenter_qa::RegisterManualProbe(authority,access);}catch(const std::exception&){failed=true;}
            check(failed);access.reset();check(!retained.expired());
            bool refused=false;try {emit(1,4,101);}catch(const mgr_err::Error&){refused=true;}
            check(refused && mgr_db::calls().empty() && mgr_db::reads().empty() && dispatches==0);return 0;
        }
        paymenter_qa::RegisterManualProbe(authority,access);
        if(scenario=="duplicate-registration") {
            bool failed=false;try {paymenter_qa::RegisterManualProbe(authority,access);}catch(const paymenter_qa::ProbeError&){failed=true;}
            check(failed && callback::Store<event::PaymentStatusChanged>().values.size()==1);
        }
        if(scenario=="foreign-callback" || scenario=="owned-no-context") {
            bool refused=false;try {emit(1,4,scenario=="foreign-callback"?999:101);}catch(const mgr_err::Error&){refused=true;}
            check(refused==(scenario=="owned-no-context") && authority->records==0 && mgr_db::reads().empty() && mgr_db::calls().empty());return 0;
        }
        if(scenario=="fork") {
            auto pid=fork();check(pid>=0);if(pid==0){bool refused=false;try {emit(1,4,101);}catch(const mgr_err::Error&){refused=true;}_exit(refused && mgr_db::reads().empty()?0:2);}
            int status=0;check(waitpid(pid,&status,0)==pid && WIFEXITED(status) && WEXITSTATUS(status)==0);
        }
        isp_api::Session s(own_action());params(s);expected_session=&s;mutable_session=&s;
        if(scenario=="before-journal")params(s,"M2");
        if(scenario=="after-journal")params(s,"M3");
        if(scenario=="wrong-actor")s.auth.actor="other-admin";
        if(scenario=="wrong-role")s.auth.role=0;
        if(scenario=="wrong-run")s.params["qa_run"]=std::string(64,'c');
        if(scenario=="wrong-nonce")s.params["qa_nonce"]=std::string(64,'c');
        if(scenario=="wrong-payment")s.params["qa_payment"]="999";
        if(scenario=="M5-read-only")params(s,"M5");
        if(scenario=="unknown-slot")s.params["qa_slot"]="override";
        if(scenario=="inherited-selector")s.params["elid"]="999";
        if(scenario=="inherited-su")s.params["su"]="customer";
        if(scenario=="phase-override")s.params["qa_phase"]="normal";
        if(scenario=="format-retry")s.params["out"]="devel";
        if(scenario=="wrong-func-param")s.params["func"]="payment.setpaid.one";
        if(scenario=="missing-cache")db.reset();
        if(scenario=="expiry-during-read")short_expiry=true;
        bool duplicate=scenario.find("duplicate-native-")==0 || scenario=="duplicate-callback-noop" || scenario=="duplicate-baseline-mismatch";
        if(duplicate)params(s,"M4","first");
        if(scenario=="original-error" || scenario=="same-thread-after-native-error") {
            bool original=false;try {invoke(s);}catch(const NativeFailure&){original=true;}
            check(original && ManualScope::current()==nullptr && mgr_db::calls().empty());
            if(scenario=="same-thread-after-native-error") {params(s,"M2");check(!invoke(s).empty() && dispatches==2);}
            check(unsafe_calls==0);return 0;
        }
        auto error=invoke(s);
        if(duplicate) {
            check(error.empty() && mgr_db::calls().size()==1);params(s,"M4","second");
            if(scenario=="duplicate-native-error") {
                bool original=false;try {invoke(s);}catch(const NativeFailure&){original=true;}
                check(original && dispatches==2);
            } else {
                auto e=invoke(s);check(e.empty()!=(scenario=="duplicate-baseline-mismatch"));
                check(dispatches==(scenario=="duplicate-baseline-mismatch"?1:2));
                if(e.empty())check(s.xml.bytes=="<doc><native-duplicate/></doc>");
            }
            check(mgr_db::calls().size()==1 && unsafe_calls==0 && ManualScope::current()==nullptr);return 0;
        }
        if(scenario=="reused-case" || scenario=="reused-nonce") {
            check(error.empty());if(scenario=="reused-nonce"){params(s,"M2");s.params["qa_nonce"]=std::string(64,'b');}
            check(!invoke(s).empty() && dispatches==1 && mgr_db::calls().size()==1);return 0;
        }
        bool success=scenario=="normal" || scenario=="preliminary-commit" || scenario=="duplicate-registration" || scenario=="fork";
        check(error.empty()==success);check(unsafe_calls==0 && ManualScope::current()==nullptr);
        const std::set<std::string> before_native={"wrong-actor","wrong-role","wrong-run","wrong-nonce","wrong-payment",
            "M5-read-only","unknown-slot","inherited-selector","inherited-su","phase-override","format-retry","wrong-func-param",
            "enabled-user","direct-notifier","queue-not-isolated","linked-service","provider-link","wrong-native-owner",
            "wrong-native-amount","wrong-native-currency","missing-cache","expiry-during-read","nested-inspect-caught"};
        if(before_native.count(scenario))check(dispatches==0);
        size_t journals=success || scenario=="after-journal" || scenario=="double-callback" ? 1:0;
        check(mgr_db::calls().size()==journals);
        if(success) {
            check(dispatches==1 && s.xml.bytes=="<doc><native-ok/></doc>");
            const auto& call=mgr_db::calls()[0];check(call.sql.find("paymenter_qa_journal_v1")!=std::string::npos);
            check(call.values==std::vector<std::string>({std::string(64,'a'),"M1","M1",std::string(64,'f')}));
        }
        return 0;
    } catch(const std::exception& e) {std::cerr<<scenario<<": "<<e.what()<<'\n';return 1;}
}
