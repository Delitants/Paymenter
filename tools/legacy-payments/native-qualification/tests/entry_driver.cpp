#include "bootstrap.hpp"
#include <api/action.h>
#include <api/module.h>
#include <mgr/mgrdb_struct.h>
#include <iostream>
std::shared_ptr<mgr_db::Cache> db(new mgr_db::Cache);
static bool available=true;
namespace paymenter_qa {
class EntryAuthority:public FrameworkAuthority {
public:
    std::string operation_id() const override {return std::string(64,'a');}
    Grant authorize(const Request& r) const override {
        return {r.run_id,r.case_id,r.nonce,r.actor,std::string(64,'c'),Mode::Normal,
            std::chrono::steady_clock::now()+std::chrono::seconds(100)};
    }
    bool current(const Grant&) const override {return true;}
    bool barrier_released(const Grant&) const override {return true;}
    void record_refusal(const Request&) const override {}
    void record_checkpoint(const Request&,const Grant&,Checkpoint) const override {}
    void record_connection(const Request&,const Grant&,const std::string&) const override {}
};
std::shared_ptr<const FrameworkAuthority> OpenProtectedFrameworkAuthority(const std::string& path) {
    if(!available || path!="/fictional/operation")throw ProbeError("fictional-entry-refusal");
    return std::make_shared<EntryAuthority>();
}
}
int main(int argc,char**argv) {
    if(argc!=2)return 2;
    const std::string scenario(argv[1]);
    available=scenario!="missing";
    if(!isp_api::test_actions().empty())return 3;
    if(lib_init()!=available)return 4;
    if(!available)return isp_api::test_actions().empty()?0:5;
    // The real entry is exercised, but readiness is authored from the vendor's
    // BILLmanager example. A loaded library alone must not dispatch its callback.
    if(scenario=="deferred" || scenario=="unrelated") {
        const std::set<std::string> ready=scenario=="deferred"?
            std::set<std::string>{}:std::set<std::string>{"database"};
        if(isp_api::test_dispatch_ready(ready)!=0 ||
           !isp_api::test_actions().empty() || !isp_api::test_events().empty())return 8;
    }
    if(isp_api::test_dispatch_ready({"billmgr"})!=1)return 9;
    if(isp_api::test_actions().size()!=1 || isp_api::test_events().size()!=1)return 6;
    if(isp_api::test_dispatch_ready({"billmgr"})!=0)return 10;
    isp_api::Session request(*isp_api::test_actions()[0]);
    request.params={{"qa_run",std::string(64,'a')},{"qa_case","F1"},{"qa_nonce",std::string(64,'b')}};
    isp_api::test_actions()[0]->Execute(request);
    isp_api::test_events()[0]->AfterExecute(request);
    return mgr_db::calls().size()==2?0:7;
}
