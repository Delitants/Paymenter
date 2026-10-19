// Offline preparation interfaces. No live issuer, native reader or SQL here.
#pragma once
#include "cache_probe.hpp"
#include <array>
#include <cstdint>
#include <set>
#include <vector>
namespace mgr_db { class Cache; }
namespace paymenter_qa { namespace manual {
using Clock=std::chrono::steady_clock;
enum class CallerSlot {Single,First,Second};
enum class Phase {Normal,BeforeJournal,AfterJournal};
enum class Checkpoint {Preflight,NativeEntered,BusinessObserved,JournalReturned};
struct Fixture {
    std::string account_id,subaccount_id,currency_id,method_id,currency_code,amount;
    std::array<std::string,4> payments;
    std::vector<std::string> users;
    std::string facts_hash;
};
struct Request {
    const void* identity;
    std::string action,actor,run_id,case_id,nonce,payment_id;
    CallerSlot slot;
};
struct Grant {
    Request request;
    Fixture fixture;
    Phase phase;
    Clock::time_point expires;
};
struct Snapshot {
    std::string payment_id,account_id,subaccount_id,currency_id,method_id;
    std::string currency_code,amount,balance;
    std::vector<std::string> users;
    int status=0;
    bool linked=false;
    bool users_disabled=false,direct_isolated=false,queue_isolated=false,provider_isolated=false;
};
inline void require(bool v,const char* code) {if(!v)throw ProbeError(code);}
inline bool digest(const std::string& value) {
    return value.size()==64 && value.find_first_not_of("0123456789abcdef")==std::string::npos;
}
inline int parse_id(const std::string& value) {
    require(!value.empty() && value.size()<=10 && value[0]>='1' && value[0]<='9',"manual-id");
    uint64_t n=0;
    for(char c:value) {
        require(c>='0' && c<='9',"manual-id");n=n*10+static_cast<unsigned>(c-'0');
        require(n<=2147483647ULL,"manual-id");
    }
    return static_cast<int>(n);
}
inline int64_t money_units(const std::string& value) {
    bool neg=!value.empty() && value[0]=='-';size_t begin=neg?1:0;
    size_t dot=value.find('.');
    require(dot!=std::string::npos && dot>begin && dot-begin<=14 && value.size()-dot==5,
            "manual-money");
    require(dot-begin==1 || value[begin]!='0',"manual-money");
    int64_t n=0;
    for(size_t i=begin;i<value.size();++i) {
        if(i==dot)continue;
        require(value[i]>='0' && value[i]<='9',"manual-money");n=n*10+(value[i]-'0');
    }
    require(!neg || n!=0,"manual-money");return neg?-n:n;
}
inline bool same_request(const Request& a,const Request& b) {
    return a.identity==b.identity && a.action==b.action && a.actor==b.actor &&
        a.run_id==b.run_id && a.case_id==b.case_id && a.nonce==b.nonce &&
        a.payment_id==b.payment_id && a.slot==b.slot;
}
inline Phase phase_for(const std::string& value) {
    if(value=="M1" || value=="M4")return Phase::Normal;
    if(value=="M2")return Phase::BeforeJournal;
    if(value=="M3")return Phase::AfterJournal;
    throw ProbeError("manual-case"); // M5 never dispatches or retries a payment.
}
inline void validate(const Request& r,const Grant& g,const std::string& operation) {
    require(r.identity && r.action=="paymenter.qa.manual" && !r.actor.empty() && r.actor.size()<=128,
            "manual-request");
    for(unsigned char c:r.actor)require(c>=32 && c!=127,"manual-actor");
    require(digest(operation) && digest(r.run_id) && digest(r.nonce) &&
            r.run_id==operation && same_request(r,g.request),"manual-binding");
    Phase expected=phase_for(r.case_id);require(g.phase==expected,"manual-phase");
    require(r.slot==CallerSlot::Single || r.slot==CallerSlot::First || r.slot==CallerSlot::Second,
            "manual-slot");
    require(r.case_id=="M4" ? r.slot!=CallerSlot::Single : r.slot==CallerSlot::Single,"manual-slot");
    const auto& f=g.fixture;
    parse_id(f.account_id);parse_id(f.subaccount_id);parse_id(f.currency_id);parse_id(f.method_id);
    require(f.currency_code=="USD" && f.amount=="0.0100" && digest(f.facts_hash),"manual-fixture");
    std::set<int> payments,users;
    for(const auto& id:f.payments)require(payments.insert(parse_id(id)).second,"manual-reused-payment");
    require(!f.users.empty() && f.users.size()<=8,"manual-users");
    for(const auto& id:f.users)require(users.insert(parse_id(id)).second,"manual-reused-user");
    parse_id(r.payment_id);
    size_t index=r.case_id=="M1"?0:r.case_id=="M2"?1:r.case_id=="M3"?2:3;
    require(r.payment_id==f.payments[index],"manual-payment-binding");
    auto now=Clock::now();require(g.expires>now && g.expires-now<=std::chrono::seconds(600),"manual-expiry");
}
inline void validate_snapshot(const Snapshot& s,const Grant& g) {
    const auto& f=g.fixture;
    require(s.payment_id==g.request.payment_id && s.account_id==f.account_id &&
        s.subaccount_id==f.subaccount_id && s.currency_id==f.currency_id && s.method_id==f.method_id &&
        s.currency_code=="USD" && s.amount=="0.0100" && s.users==f.users,"manual-native-binding");
    money_units(s.balance);
    require(!s.linked && s.users_disabled && s.direct_isolated && s.queue_isolated &&
            s.provider_isolated,"manual-isolation");
}
class Authority {
public:
    virtual ~Authority() {}
    virtual std::string operation_id() const=0;
    virtual Grant authorize(const Request&) const=0;
    virtual bool current(const Grant&) const=0;
    // Immutable admitted membership only; no I/O, SQL or callback reentry.
    virtual bool owns(int payment) const noexcept=0;
    // Bounded private diagnostics; never a transaction/acceptance claim.
    virtual void record(const Request&,const Grant&,Checkpoint) const=0;
};
class NativeAccess {
public:
    virtual ~NativeAccess() {}
    // Mandatory source-only read adapter, deliberately not implemented here.
    virtual Snapshot inspect(const Fixture&,int payment,mgr_db::Cache&)=0;
};
} }
