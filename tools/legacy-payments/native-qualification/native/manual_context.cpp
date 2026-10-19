#include "manual_context.hpp"
#include <exception>
#include <unistd.h>
namespace paymenter_qa { namespace manual {
thread_local ManualScope* ManualScope::active_=nullptr;
ManualScope::ManualScope(const Request& r,const Grant& g,const void* cache,const void* connection):
    request_(r),grant_(g),cache_(cache),connection_(connection),process_(getpid()),
    worker_(std::this_thread::get_id()),deadline_(Clock::now()+std::chrono::seconds(30)) {
    require(active_==nullptr,"manual-nested-context");
    validate(r,g,r.run_id);require(cache && connection,"manual-cache");
    active_=this;
}
ManualScope::~ManualScope() noexcept {
    // Stack-affine, nonmovable; SDK workers never receive ownership of this scope.
    if(worker_!=std::this_thread::get_id())std::terminate();
    if(active_==this)active_=nullptr;
}
ManualScope* ManualScope::current() noexcept {
    if(!active_ || active_->process_!=getpid() ||
       active_->worker_!=std::this_thread::get_id())return nullptr;
    return active_;
}
void ManualScope::owner() const {
    require(process_==getpid() && worker_==std::this_thread::get_id() && active_==this,
            "manual-context-owner");
}
void ManualScope::check(const Request& r,const void* cache,const void* connection) const {
    owner();require(same_request(r,request_),"manual-context-binding");
    validate(r,grant_,request_.run_id);
    require(Clock::now()<deadline_,"manual-case-deadline");
    require(cache==cache_ && connection==connection_,"manual-cache-connection");
}
void ManualScope::enter_native() {
    check(request_,cache_,connection_);require(!entered_ && !used_,"manual-native-reentry");
    entered_=true;
}
CallbackToken ManualScope::consume(int old_status,int new_status,int payment,
                                  const void* cache,const void* connection) {
    owner();require(!used_,"manual-reused-callback");used_=true;
    // Reservation precedes all caller diagnostics, reads and intentional throws.
    check(request_,cache,connection);require(entered_,"manual-before-native");
    require(payment==parse_id(request_.payment_id),"manual-callback-payment");
    bool duplicate=request_.case_id=="M4" && request_.slot==CallerSlot::Second &&
                   old_status==4 && new_status==4;
    require((old_status==1 && new_status==4) || duplicate,"manual-callback-status");
    return CallbackToken(request_,grant_,duplicate);
}
} }
