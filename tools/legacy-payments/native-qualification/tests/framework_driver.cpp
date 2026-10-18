// Local tests of our guards and emitted calls. No vendor runtime or DB behavior.
#include "cache_probe.hpp"
#include <api/action.h>
#include <mgr/mgrdb_struct.h>
#include <chrono>
#include <functional>
#include <iostream>
#include <memory>
#include <stdexcept>
#include <string>
#include <thread>
#include <sys/wait.h>
#include <unistd.h>

std::shared_ptr<mgr_db::Cache> db(new mgr_db::Cache);
using namespace paymenter_qa;
using Clock = std::chrono::steady_clock;
static const std::string RUN(64,'a'), NONCE(64,'b'), FACTS(64,'c');
void check(bool ok) { if (!ok) throw std::runtime_error("test assertion"); }
void refuses(const std::function<void()>& f) {
    bool refused = false;
    try { f(); } catch (const ProbeError&) { refused = true; }
    check(refused);
}
class FictionalAuthority: public FrameworkAuthority {
public:
    std::string case_id = "F1";
    Mode mode = Mode::Normal;
    mutable bool valid = true;
    bool release = true, revoke_release = false;
    int current_delay = 0, release_delay = 0;
    std::string grant_actor = "fictional-admin", grant_nonce = NONCE, grant_run = RUN;
    mutable int authorizations = 0;
    mutable std::vector<std::string> recorded_connections;
    mutable std::vector<int> checkpoints;
    Clock::time_point expiry = Clock::now() + std::chrono::seconds(300);
    std::string operation_id() const override { return RUN; }
    Grant authorize(const Request&) const override {
        ++authorizations;
        return {grant_run, case_id, grant_nonce, grant_actor, FACTS, mode, expiry};
    }
    bool current(const Grant&) const override {
        std::this_thread::sleep_for(std::chrono::milliseconds(current_delay));
        return valid;
    }
    bool barrier_released(const Grant&) const override {
        std::this_thread::sleep_for(std::chrono::milliseconds(release_delay));
        if (revoke_release) valid=false;
        return release;
    }
    void record_refusal(const Request&) const override {}
    void record_checkpoint(const Request&,const Grant&,Checkpoint stage) const override { checkpoints.push_back(static_cast<int>(stage)); }
    void record_connection(const Request&, const Grant&, const std::string& id) const override {
        recorded_connections.push_back(id);
    }
};
struct Fixture {
    std::shared_ptr<FictionalAuthority> authority{new FictionalAuthority};
    const isp_api::Action* action;
    const isp_api::Event* event;
    std::unique_ptr<isp_api::Session> request;
    Fixture() {
        RegisterFrameworkProbe(authority);
        check(isp_api::test_actions().size()==1 && isp_api::test_events().size()==1);
        action=isp_api::test_actions()[0]; event=isp_api::test_events()[0];
        check(action->name()=="paymenter.qa.transaction" && action->access==mgr_access::AccessAdmin);
        check(event->action==action->name() && event->priority==isp_api::Event::epAfter);
        request.reset(new isp_api::Session(*action));
        request->params={{"qa_run",RUN},{"qa_case","F1"},{"qa_nonce",NONCE}};
    }
    void select(const std::string& id, Mode mode) {
        authority->case_id=id; authority->mode=mode; request->params["qa_case"]=id;
    }
    void execute() { action->Execute(*request); }
    void after() { event->AfterExecute(*request); }
    void final() { event->Final(*request); }
};
void exact_calls() {
    const auto& calls=mgr_db::calls(); check(calls.size()==2 && mgr_db::caches()==1);
    check(calls[0].sql=="INSERT INTO paymenter_qa_business_v1 (run_id,case_id,amount,facts_hash) VALUES (?,?,?,?)");
    check(calls[1].sql=="INSERT INTO paymenter_qa_journal_v1 (run_id,case_id,business_case_id,facts_hash) VALUES (?,?,?,?)");
    check(calls[0].values==std::vector<std::string>({RUN,"F1","0.0100",FACTS}));
    check(calls[1].values==std::vector<std::string>({RUN,"F1","F1",FACTS}));
}
int main(int argc,char** argv) {
    try {
        check(argc==2); std::string test=argv[1];
        if (test=="missing-authority") {
            refuses([] { RegisterFrameworkProbe(nullptr); });
            check(isp_api::test_actions().empty() && isp_api::test_events().empty());
            return 0;
        }
        if (test=="inert-link") {
            check(isp_api::test_actions().empty() && isp_api::test_events().empty() && mgr_db::calls().empty());
            return 0;
        }
        Fixture f;
        if (test=="checkpoint-normal") {f.execute();f.after();check(f.authority->checkpoints==std::vector<int>({0,1}));}
        else if (test=="checkpoint-F2") {f.select("F2",Mode::ThrowAfterBusiness);refuses([&]{f.execute();});check(f.authority->checkpoints==std::vector<int>({0,2}));}
        else if (test=="checkpoint-F3") {f.select("F3",Mode::ThrowAfterJournal);f.execute();refuses([&]{f.after();});check(f.authority->checkpoints==std::vector<int>({0,1,3}));}
        else if (test=="checkpoint-sql-failed") {mgr_db::on_write()=[] {throw ProbeError("authored-write-failed");};refuses([&]{f.execute();});check(f.authority->checkpoints.empty());}
        else if (test=="normal") { f.execute(); f.after(); f.final(); exact_calls(); }
        else if (test=="wrong-run") { f.request->params["qa_run"]=std::string(64,'d'); refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="wrong-case") { f.request->params["qa_case"]="M1"; refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="wrong-nonce") { f.request->params["qa_nonce"]=std::string(64,'d'); refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="wrong-actor") { f.request->auth.actor="fictional-other"; refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="wrong-role") { f.request->auth.role=3; refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="expired") { f.authority->expiry=Clock::now()-std::chrono::seconds(1); refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="unbounded-expiry") { f.authority->expiry=Clock::now()+std::chrono::seconds(601); refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="missing-registry") { f.authority->valid=false; refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="wrong-grant") { f.authority->grant_run=std::string(64,'e'); refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="phase-override") { f.select("F2",Mode::ThrowAfterBusiness); f.request->params["qa_phase"]="normal"; refuses([&]{f.execute();}); check(mgr_db::calls().size()==1); }
        else if (test=="throw-after-journal") { f.select("F3",Mode::ThrowAfterJournal); f.execute(); refuses([&]{f.after();}); check(mgr_db::calls().size()==2); }
        else if (test=="mode-mismatch") { f.authority->mode=Mode::ThrowAfterJournal; refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="reused-case") { f.execute(); f.after(); f.final(); f.authority->grant_nonce=std::string(64,'e'); f.request->params["qa_nonce"]=std::string(64,'e'); refuses([&]{f.execute();}); check(mgr_db::calls().size()==2); }
        else if (test=="nested") {
            mgr_db::on_write()=[&] { isp_api::Session nested(*f.action); nested.params=f.request->params; refuses([&]{f.action->Execute(nested);}); };
            f.execute(); f.after(); f.final(); check(mgr_db::calls().size()==2);
        }
        else if (test=="nested-connection-callback") {
            bool entered=false;
            mgr_db::on_connection()=[&] { if (!entered) { entered=true; isp_api::Session nested(*f.action); nested.params=f.request->params; refuses([&]{f.action->Execute(nested);}); } };
            f.execute(); f.after(); f.final(); exact_calls();
        }
        else if (test=="reentrant-after") {
            bool entered=false;
            mgr_db::on_write()=[&] { if (mgr_db::calls().size()==2 && !entered) { entered=true; refuses([&]{f.after();}); } };
            f.execute(); f.after(); f.final(); exact_calls();
        }
        else if (test=="connection-change") { f.execute(); db->connection=1; refuses([&]{f.after();}); check(mgr_db::calls().size()==1); }
        else if (test=="server-connection-change") { f.execute(); db->server_id="72"; refuses([&]{f.after();}); check(mgr_db::calls().size()==1); }
        else if (test=="invalid-server-connection") { db->server_id="00"; refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="connection-diagnostic") { f.execute(); f.after(); f.final(); check(f.authority->recorded_connections==std::vector<std::string>({"71"})); check(!mgr_db::reads().empty()); for (auto& query:mgr_db::reads()) check(query=="SELECT CONNECTION_ID()"); }
        else if (test=="missing-cache") { db.reset(); refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="cache-change") { f.execute(); db.reset(new mgr_db::Cache); refuses([&]{f.after();}); check(mgr_db::calls().size()==1); }
        else if (test=="revoked-after-business") { f.execute(); f.authority->valid=false; refuses([&]{f.after();}); check(mgr_db::calls().size()==1); }
        else if (test=="role-revoked-after-business") { f.execute(); f.request->auth.role=3; refuses([&]{f.after();}); check(mgr_db::calls().size()==1); }
        else if (test=="expiry-during-authority-read") { f.authority->expiry=Clock::now()+std::chrono::milliseconds(100); f.execute(); f.authority->current_delay=150; refuses([&]{f.after();}); check(mgr_db::calls().size()==1); }
        else if (test=="expired-after-business") { f.authority->expiry=Clock::now()+std::chrono::milliseconds(10); f.execute(); std::this_thread::sleep_for(std::chrono::milliseconds(20)); refuses([&]{f.after();}); check(mgr_db::calls().size()==1); }
        else if (test=="wrong-session") { f.execute(); isp_api::Session other(*f.action); other.params=f.request->params; refuses([&]{f.event->AfterExecute(other);}); check(mgr_db::calls().size()==1); f.final(); }
        else if (test=="wrong-thread") { f.execute(); std::thread t([&] { refuses([&]{f.after();}); }); t.join(); check(mgr_db::calls().size()==1); f.final(); }
        else if (test=="final-is-not-commit") { f.execute(); f.final(); refuses([&]{f.after();}); check(mgr_db::calls().size()==1); }
        else if (test=="observer-release") { f.select("F4",Mode::ObserveBeforeReturn); f.execute(); f.after(); f.final(); check(mgr_db::calls().size()==2); }
        else if (test=="observer-late-release") { f.select("F4",Mode::ObserveBeforeReturn); f.authority->expiry=Clock::now()+std::chrono::milliseconds(100); f.authority->release_delay=150; f.execute(); refuses([&]{f.after();}); check(mgr_db::calls().size()==2); }
        else if (test=="observer-revoked-on-release") { f.select("F4",Mode::ObserveBeforeReturn); f.authority->revoke_release=true; f.execute(); refuses([&]{f.after();}); check(mgr_db::calls().size()==2); }
        else if (test=="observer-timeout") { f.select("F4",Mode::ObserveBeforeReturn); f.authority->release=false; f.execute(); auto start=Clock::now(); refuses([&]{f.after();}); auto elapsed=Clock::now()-start; check(elapsed>=std::chrono::seconds(4) && elapsed<std::chrono::seconds(6)); check(mgr_db::calls().size()==2); }
        else if (test=="process-bound") { pid_t p=fork(); check(p>=0); if (!p) { try { refuses([&]{f.execute();}); _exit(mgr_db::calls().empty()?0:3); } catch (...) { _exit(4); } } int status=0; check(waitpid(p,&status,0)==p && WIFEXITED(status) && WEXITSTATUS(status)==0); }
        else if (test=="registration-collision") { refuses([&]{RegisterFrameworkProbe(f.authority);}); check(isp_api::test_actions().size()==1 && isp_api::test_events().size()==1); }
        else if (test=="injected-table") { f.request->params["qa_table"]="payment"; f.request->params["sql"]="COMMIT"; f.execute(); f.after(); f.final(); exact_calls(); }
        else if (test=="invalid-case-data") { f.request->params["qa_case"]="F1, F2"; refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else if (test=="guard-case") { f.request->params["qa_case"]="F5"; refuses([&]{f.execute();}); check(mgr_db::calls().empty()); }
        else check(false);
        if (mgr_db::calls().empty()) check(mgr_db::reads().empty() || test=="invalid-server-connection");
        std::cout << "PASS local guard and call-boundary case\n";
        return 0;
    } catch (const std::exception& e) { std::cerr << e.what() << '\n'; return 1; }
}
