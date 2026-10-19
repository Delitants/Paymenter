// Authored values exercise our guards, not source ownership or DB semantics.
#include "manual_contract.hpp"
#include <iostream>
#include <functional>
using namespace paymenter_qa::manual;
namespace {
void check(bool v) { if (!v) throw std::runtime_error("test assertion"); }
Fixture fixture() {
    return {"11","12","13","14","USD","0.0100",
            {{"101","102","103","104"}},{"21"},std::string(64,'f')};
}
Request request() {
    static int identity;
    return {&identity,"paymenter.qa.manual","fictional-admin",std::string(64,'a'),
            "M1",std::string(64,'b'),"101",CallerSlot::Single};
}
Grant grant(const Request& r) {
    return {r,fixture(),Phase::Normal,Clock::now()+std::chrono::seconds(60)};
}
}
int main(int argc,char** argv) {
    try {
        check(argc==2);std::string name=argv[1];auto r=request();auto g=grant(r);
        if(name=="valid") {
            validate(r,g,r.run_id);check(parse_id("2147483647")==2147483647);
            check(money_units("-123.4500")==-1234500);
            check(money_units("99999999999999.9999")==999999999999999999LL);
            return 0;
        }
        std::function<void()> operation=[&] {validate(r,g,std::string(64,'a'));};
        if(name=="bad-id")g.fixture.account_id="1x";
        else if(name=="zero-id")g.fixture.subaccount_id="0";
        else if(name=="negative-id")g.fixture.method_id="-1";
        else if(name=="overflow-id")g.fixture.currency_id="2147483648";
        else if(name=="leading-zero")g.fixture.payments[0]="0101";
        else if(name=="group-selector")r.payment_id="101, 102";
        else if(name=="duplicate-payments")g.fixture.payments[1]="101";
        else if(name=="duplicate-users")g.fixture.users.push_back("21");
        else if(name=="empty-users")g.fixture.users.clear();
        else if(name=="wrong-actor")r.actor="other-admin";
        else if(name=="wrong-run")r.run_id=std::string(64,'c');
        else if(name=="wrong-nonce")r.nonce=std::string(64,'c');
        else if(name=="wrong-case")r.case_id="M2";
        else if(name=="wrong-payment")r.payment_id="102";
        else if(name=="unknown-slot")r.slot=static_cast<CallerSlot>(99);
        else if(name=="second-slot-on-M1")r.slot=CallerSlot::Second;
        else if(name=="single-slot-on-M4") {r.case_id="M4";r.payment_id="104";g=grant(r);}
        else if(name=="M5-is-read-only") {r.case_id="M5";g=grant(r);}
        else if(name=="wrong-phase")g.phase=Phase::BeforeJournal;
        else if(name=="wrong-currency")g.fixture.currency_code="EUR";
        else if(name=="wrong-amount")g.fixture.amount="0.0200";
        else if(name=="expired")g.expires=Clock::now()-std::chrono::seconds(1);
        else if(name=="unbounded-expiry")g.expires=Clock::now()+std::chrono::seconds(601);
        else if(name=="missing-session")r.identity=nullptr;
        else if(name=="bad-facts")g.fixture.facts_hash=std::string(64,'G');
        else if(name=="money-scale")operation=[] {money_units("0.01");};
        else if(name=="money-overflow")operation=[] {money_units("100000000000000.0000");};
        else throw std::runtime_error("unknown scenario");
        bool refused=false;try {operation();} catch(const paymenter_qa::ProbeError&) {refused=true;}
        check(refused);return 0;
    } catch(const std::exception& e) {std::cerr<<e.what()<<'\n';return 1;}
}
