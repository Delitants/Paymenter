// Authored values exercise our guards, not source ownership or DB semantics.
#include "manual_contract.hpp"
#include "manual_context.hpp"
#include <iostream>
#include <functional>
#include <thread>
#include <sys/wait.h>
#include <unistd.h>
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
void scope_case(const std::string& name) {
    auto r=request();auto g=grant(r);int cache=0,connection=0,foreign=0;
    check(ManualScope::current()==nullptr);
    if(name=="scope-early-return") {
        [&] {ManualScope scope(r,g,&cache,&connection);return;}();
    } else if(name=="scope-unwind-before-callback" || name=="scope-unwind-after-callback" ||
              name=="scope-same-thread-reuse") {
        try {
            ManualScope scope(r,g,&cache,&connection);
            if(name=="scope-unwind-after-callback") {
                scope.enter_native();auto token=scope.consume(1,4,101,&cache,&connection);
                check(token.request.identity==r.identity);
            }
            throw std::runtime_error("modeled native failure");
        } catch(const std::runtime_error&) {}
        check(ManualScope::current()==nullptr);
        if(name=="scope-same-thread-reuse") {
            r.case_id="M2";r.payment_id="102";r.nonce=std::string(64,'c');g=grant(r);g.phase=Phase::BeforeJournal;
            ManualScope next(r,g,&cache,&connection);next.enter_native();
            auto token=next.consume(1,4,102,&cache,&connection);check(token.request.case_id=="M2");
        }
    } else {
        if(name=="scope-expired")g.expires=Clock::now()+std::chrono::milliseconds(10);
        ManualScope scope(r,g,&cache,&connection);check(ManualScope::current()==&scope);
        if(name!="scope-before-native")scope.enter_native();
        if(name=="scope-normal") {
            auto token=scope.consume(1,4,101,&cache,&connection);check(token.request.payment_id=="101");
        } else if(name=="scope-wrong-thread") {
            bool refused=false;
            std::thread worker([&] {check(ManualScope::current()==nullptr);
                try {scope.consume(1,4,101,&cache,&connection);}catch(const paymenter_qa::ProbeError&){refused=true;}});
            worker.join();check(refused);scope.consume(1,4,101,&cache,&connection);
        } else if(name=="scope-fork") {
            auto pid=fork();check(pid>=0);
            if(pid==0) {
                if(ManualScope::current()!=nullptr)_exit(2);
                try {scope.consume(1,4,101,&cache,&connection);}catch(const paymenter_qa::ProbeError&){_exit(0);}
                _exit(3);
            }
            int status=0;check(waitpid(pid,&status,0)==pid && WIFEXITED(status) && WEXITSTATUS(status)==0);
            scope.consume(1,4,101,&cache,&connection);
        } else {
            bool refused=false;
            try {
                if(name=="scope-nested") {ManualScope nested(r,g,&cache,&connection);}
                else if(name=="scope-wrong-cache")scope.consume(1,4,101,&foreign,&connection);
                else if(name=="scope-wrong-connection")scope.consume(1,4,101,&cache,&foreign);
                else if(name=="scope-repeat") {scope.consume(1,4,101,&cache,&connection);scope.consume(1,4,101,&cache,&connection);}
                else if(name=="scope-before-native")scope.consume(1,4,101,&cache,&connection);
                else if(name=="scope-wrong-payment")scope.consume(1,4,102,&cache,&connection);
                else if(name=="scope-wrong-status")scope.consume(2,4,101,&cache,&connection);
                else if(name=="scope-expired") {std::this_thread::sleep_for(std::chrono::milliseconds(20));scope.consume(1,4,101,&cache,&connection);}
                else if(name=="scope-context-binding") {r.identity=&foreign;scope.check(r,&cache,&connection);}
                else throw std::runtime_error("unknown scope scenario");
            } catch(const paymenter_qa::ProbeError&) {refused=true;}
            check(refused);
        }
    }
    check(ManualScope::current()==nullptr);
}
}
int main(int argc,char** argv) {
    try {
        check(argc==2);std::string name=argv[1];
        if(name.find("scope-")==0){scope_case(name);return 0;}
        auto r=request();auto g=grant(r);
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
