// Lexical, thread-affine test context. No SDK Job or transaction control.
#pragma once
#include "manual_contract.hpp"
#include <thread>
namespace paymenter_qa { namespace manual {
class CallbackToken {
    friend class ManualScope;
    CallbackToken(const Request& r,const Grant& g,bool d):request(r),grant(g),duplicate(d) {}
public:
    const Request request;
    const Grant grant;
    const bool duplicate;
    CallbackToken(const CallbackToken&)=delete;
    CallbackToken& operator=(const CallbackToken&)=delete;
    CallbackToken(CallbackToken&&)=default;
};
class ManualScope {
    static thread_local ManualScope* active_;
    const Request request_;
    const Grant grant_;
    const void* const cache_;
    const void* const connection_;
    const int process_;
    const std::thread::id worker_;
    const Clock::time_point deadline_;
    bool entered_=false,used_=false,invalid_=false;
    void owner() const;
public:
    ManualScope(const Request&,const Grant&,const void* cache,const void* connection);
    ~ManualScope() noexcept;
    ManualScope(const ManualScope&)=delete;
    ManualScope& operator=(const ManualScope&)=delete;
    static ManualScope* current() noexcept;
    void check(const Request&,const void* cache,const void* connection) const;
    void enter_native();
    void invalidate() noexcept;
    CallbackToken consume(int old_status,int new_status,int payment,const void* cache,const void* connection);
};
} }
