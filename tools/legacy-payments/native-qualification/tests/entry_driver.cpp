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
void paymenter_qa_test_module_initialize();
int main(int argc,char**argv) {
    if(argc!=2)return 2;
    available=std::string(argv[1])!="missing";
    if(!isp_api::test_actions().empty())return 3;
    if(lib_init()!=available)return 4;
    paymenter_qa_test_module_initialize();
    if(!available)return isp_api::test_actions().empty()?0:5;
    if(isp_api::test_actions().size()!=1 || isp_api::test_events().size()!=1)return 6;
    isp_api::Session request(*isp_api::test_actions()[0]);
    request.params={{"qa_run",std::string(64,'a')},{"qa_case","F1"},{"qa_nonce",std::string(64,'b')}};
    isp_api::test_actions()[0]->Execute(request);
    isp_api::test_events()[0]->AfterExecute(request);
    return mgr_db::calls().size()==2?0:7;
}
