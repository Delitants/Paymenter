// Authored SDK/native-authority doubles; exercises real bootstrap and probe code.
#include "bootstrap.hpp"
#include <api/action.h>
#include <mgr/mgrdb_struct.h>
#include <functional>
#include <iostream>
#include <sys/wait.h>
#include <unistd.h>
using namespace paymenter_qa;
std::shared_ptr<mgr_db::Cache> db(new mgr_db::Cache);
static std::string scenario;
static int opens=0,checks=0;
static FrameworkBootstrap* owner=nullptr;
void check(bool value) {if(!value)throw std::runtime_error("test assertion");}
class Auth:public FrameworkAuthority {
public:
    std::string operation_id() const override {
        ++checks;
        if (scenario=="revoked-before-register" && checks>=2) throw ProbeError("fixture-revoked");
        if (scenario=="revoked-after-register" && !isp_api::test_actions().empty()) throw ProbeError("fixture-revoked");
        return scenario=="bad-operation"?"bad":std::string(64,'a');
    }
    Grant authorize(const Request& r) const override {
        return {r.run_id,r.case_id,r.nonce,r.actor,std::string(64,'c'),Mode::Normal,
            std::chrono::steady_clock::now()+std::chrono::seconds(100)};
    }
    bool current(const Grant&) const override {return true;}
    bool barrier_released(const Grant&) const override {return true;}
    void record_connection(const Request&,const Grant&,const std::string&) const override {}
};
namespace paymenter_qa {
std::shared_ptr<const FrameworkAuthority> OpenProtectedFrameworkAuthority(const std::string& path) {
    ++opens;check(path=="/fictional/operation");
    if (scenario=="missing-authority") throw ProbeError("fixture-missing");
    if (scenario=="null-authority") return nullptr;
    if (scenario=="reentrant-prepare") check(!owner->prepare(path));
    return std::make_shared<Auth>();
}
}
void invoke(bool accepted) {
    check(!isp_api::test_actions().empty());
    auto action=isp_api::test_actions().back();
    isp_api::Session request(*action);
    request.params={{"qa_run",std::string(64,'a')},{"qa_case","F1"},{"qa_nonce",std::string(64,'b')}};
    bool result=true;
    try {action->Execute(request);} catch(const ProbeError&) {result=false;}
    check(result==accepted);
    if (accepted) {
        check(isp_api::test_events().size()==1);
        isp_api::test_events()[0]->AfterExecute(request);
        check(mgr_db::calls().size()==2);
    } else check(mgr_db::calls().empty());
}
int main(int argc,char**argv) {
    try {
        check(argc==2);scenario=argv[1];
        if(scenario=="destroyed-bootstrap") {
            auto destroyed=std::unique_ptr<FrameworkBootstrap>(new FrameworkBootstrap);owner=destroyed.get();
            check(destroyed->prepare("/fictional/operation") && destroyed->initialize());
            destroyed.reset();invoke(false);return 0;
        }
        FrameworkBootstrap bootstrap;owner=&bootstrap;
        if (scenario=="initialize-without-entry") {check(!bootstrap.initialize());check(opens==0);return 0;}
        bool prepared=bootstrap.prepare("/fictional/operation");
        if (scenario=="missing-authority" || scenario=="null-authority" ||
            scenario=="bad-operation" || scenario=="reentrant-prepare") {
            check(!prepared && !bootstrap.initialize() && isp_api::test_actions().empty());return 0;
        }
        check(prepared && !bootstrap.active());
        if (scenario=="duplicate-prepare") {
            check(!bootstrap.prepare("/fictional/operation"));check(!bootstrap.initialize());return 0;
        }
        if (scenario=="event-registration-failure") isp_api::test_event_failure()=true;
        if (scenario=="registration-reentrant") isp_api::test_event_hook()=[&]{check(!bootstrap.initialize());};
        if (scenario=="pending-action") isp_api::test_event_hook()=[]{invoke(false);};
        bool initialized=bootstrap.initialize();
        if (scenario=="revoked-before-register") {
            check(!initialized && isp_api::test_actions().empty());return 0;
        }
        if (scenario=="event-registration-failure" || scenario=="registration-reentrant" || scenario=="revoked-after-register") {
            check(!initialized && !bootstrap.active());invoke(false);return 0;
        }
        check(initialized && bootstrap.active());
        if (scenario=="duplicate-initialize") {
            check(!bootstrap.initialize() && !bootstrap.active());invoke(false);return 0;
        }
        if (scenario=="prepare-after-active") {
            check(!bootstrap.prepare("/fictional/operation") && !bootstrap.active());invoke(false);return 0;
        }
        if (scenario=="fork-refusal") {
            pid_t child=fork();check(child>=0);
            if (!child) _exit(!bootstrap.active() && !bootstrap.initialize()?0:1);
            int status=0;check(waitpid(child,&status,0)==child && WIFEXITED(status) && WEXITSTATUS(status)==0);
            check(bootstrap.active());return 0;
        }
        invoke(true);return 0;
    } catch(const std::exception& e) {std::cerr<<e.what()<<'\n';return 1;}
}
