// Real bootstrap+adapter, authored SDK only. No source/native acceptance.
#include "bootstrap.hpp"
#include "manual_probe.hpp"
#include <api/action.h>
#include <api/module.h>
#include <billmgr/util/internal/callback_internal.h>
#include <mgr/mgrdb_struct.h>
#include <iostream>
std::shared_ptr<mgr_db::Cache> db(new mgr_db::Cache);
namespace paymenter_qa {
class FrameworkTestAuthority:public FrameworkAuthority {
public:
 std::string operation_id()const override{return std::string(64,'a');}
 Grant authorize(const Request& r)const override{return {r.run_id,r.case_id,r.nonce,r.actor,std::string(64,'c'),Mode::Normal,std::chrono::steady_clock::now()+std::chrono::seconds(60)};}
 bool current(const Grant&)const override{return true;}
 bool barrier_released(const Grant&)const override{return true;}
 void record_refusal(const Request&)const override{}
 void record_checkpoint(const Request&,const Grant&,Checkpoint)const override{}
 void record_connection(const Request&,const Grant&,const std::string&)const override{}
};
std::shared_ptr<const FrameworkAuthority> OpenProtectedFrameworkAuthority(const std::string& p){
 if(p!="/fictional/operation")throw ProbeError("fictional path");
 return std::make_shared<FrameworkTestAuthority>();
}
class ManualTestAuthority:public manual::Authority {
public:
 std::string operation_id()const override{throw ProbeError("unexpected authority access");}
 manual::Grant authorize(const manual::Request&)const override{throw ProbeError("unexpected grant");}
 bool current(const manual::Grant&)const override{throw ProbeError("unexpected gate read");}
 bool owns(int)const noexcept override{return false;}
 manual::FirstResult first_result(const manual::Grant&)const override{throw ProbeError("unexpected first result");}
 void record(const manual::Request&,const manual::Grant&,manual::Checkpoint)const override{throw ProbeError("unexpected record");}
 void record_connection(const manual::Request&,const manual::Grant&,const std::string&)const override{throw ProbeError("unexpected connection record");}
};
}
namespace isp_api {
Xml InternalCall(const Session&,const std::string&,const std::string&){throw std::runtime_error("unexpected native dispatch");}
Xml InternalCall(const std::string&,const std::string&){throw std::runtime_error("unexpected unbound dispatch");}
Authen Authenticate(){throw std::runtime_error("unexpected auth");}
}
int main(int argc,char**argv){
 try {
  if(argc!=2)return 2;
  std::string name=argv[1];
  if(name=="missing-access"){
   bool refused=false;
   try{paymenter_qa::RegisterManualProbe(std::make_shared<paymenter_qa::ManualTestAuthority>(),nullptr);}
   catch(const paymenter_qa::ProbeError& e){refused=std::string(e.what())=="manual-native-dependencies-unavailable";}
   if(!refused || !isp_api::test_actions().empty())return 3;
  }else{
   if(!lib_init() || isp_api::test_dispatch_ready({"billmgr"})!=1)return 4;
   if(isp_api::test_actions().size()!=1 || isp_api::test_events().size()!=1 ||
      !isp_api::HasAction("paymenter.qa.transaction") || isp_api::HasAction("paymenter.qa.manual"))return 5;
  }
  return callback::Store<event::PaymentStatusChanged>().values.empty() && mgr_db::reads().empty() && mgr_db::calls().empty()?0:6;
 }catch(const std::exception& e){std::cerr<<e.what()<<'\n';return 1;}
}
