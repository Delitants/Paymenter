// Generic offline preparation adapter. Not enabled by the framework bootstrap.
#include "manual_probe.hpp"
#include "manual_context.hpp"
#include <api/action.h>
#include <billmgr/util/internal/callback_internal.h>
#include <mgr/mgrdb_struct.h>
#include <mgr/mgrerr.h>
#include <atomic>
#include <exception>
#include <mutex>
#include <unistd.h>
extern std::shared_ptr<mgr_db::Cache> db;
namespace paymenter_qa {
namespace {
using namespace manual;
using manual::Request;
using manual::Grant;
using manual::Checkpoint;
const char* const ACTION="paymenter.qa.manual";
const char* const JOURNAL="INSERT INTO paymenter_qa_journal_v1 (run_id,case_id,business_case_id,facts_hash) VALUES (?,?,?,?)";
class DispatchGuard {
    static thread_local DispatchGuard* active_;
    bool invalid_=false;
public:
    DispatchGuard() {
        if(active_) {
            active_->invalid_=true;
            if(auto s=ManualScope::current())s->invalidate();
            throw ProbeError("manual-nested-dispatch");
        }
        active_=this;
    }
    ~DispatchGuard() {active_=nullptr;}
    void check() const {require(active_==this && !invalid_,"manual-dispatch-context");}
};
thread_local DispatchGuard* DispatchGuard::active_=nullptr;
Request request(isp_api::Session& s) {
    StringVector names;s.conn.GetParams(names);
    require(names.size()<=8,"manual-parameters");
    for(const auto& n:names)require(n=="func" || n=="out" || n=="lang" || n=="qa_run" ||
        n=="qa_case" || n=="qa_nonce" || n=="qa_payment" || n=="qa_slot","manual-parameters");
    require(s.Param("func").empty() || s.Param("func")==ACTION,"manual-function");
    require(s.Param("out").empty() || s.Param("out")=="xml","manual-output");
    std::string slot=s.Param("qa_slot");
    require(slot=="single" || slot=="first" || slot=="second","manual-slot");
    return {&s,s.action.name(),s.auth.name(),s.Param("qa_run"),s.Param("qa_case"),
            s.Param("qa_nonce"),s.Param("qa_payment"),slot=="single"?CallerSlot::Single:
            slot=="first"?CallerSlot::First:CallerSlot::Second};
}
bool same_fixture(const Fixture& a,const Fixture& b) {
    return a.account_id==b.account_id && a.subaccount_id==b.subaccount_id &&
        a.currency_id==b.currency_id && a.method_id==b.method_id && a.currency_code==b.currency_code &&
        a.amount==b.amount && a.payments==b.payments && a.users==b.users && a.facts_hash==b.facts_hash;
}
bool same_snapshot(const Snapshot& a,const Snapshot& b) {
    return a.payment_id==b.payment_id && a.account_id==b.account_id && a.subaccount_id==b.subaccount_id &&
        a.currency_id==b.currency_id && a.method_id==b.method_id && a.currency_code==b.currency_code &&
        a.amount==b.amount && a.balance==b.balance && a.users==b.users && a.status==b.status &&
        a.linked==b.linked && a.users_disabled==b.users_disabled && a.direct_isolated==b.direct_isolated &&
        a.queue_isolated==b.queue_isolated && a.provider_isolated==b.provider_isolated;
}
class Probe;
struct Frame {
    Probe* probe;
    isp_api::Session& session;
    DispatchGuard& dispatch;
    ManualScope& scope;
    const Request request;
    const Grant grant;
    const int role;
    const std::shared_ptr<mgr_db::Cache> cache;
    mgr_db::Connection* const connection;
    std::string server;
    Snapshot before,observed;
    std::exception_ptr callback_failure;
    bool seen;
};
thread_local Frame* frame=nullptr;
struct FrameGuard {
    explicit FrameGuard(Frame& f) {require(frame==nullptr,"manual-frame");frame=&f;}
    ~FrameGuard() {frame=nullptr;}
};
class Probe {
    const std::shared_ptr<const Authority> authority_;
    const std::shared_ptr<NativeAccess> access_;
    const std::string operation_;
    const int process_;
    std::atomic<bool> active_{false};
    std::mutex mutex_;
    std::set<std::string> slots_,nonces_;
    std::shared_ptr<const Fixture> fixture_;
    std::string actor_;
    void enabled() const {require(getpid()==process_ && active_.load(),"manual-inactive");}
    void check(Frame& f) const {
        enabled();f.dispatch.check();
        require(frame==&f && f.probe==this && ManualScope::current()==&f.scope,"manual-frame");
        require(f.session.auth.level()==f.role && f.role>=mgr_access::lvAdmin,"manual-actor-access");
        Request now=request(f.session);
        f.scope.check(now,db.get(),f.cache->GetConnection());f.dispatch.check();
        require(authority_->operation_id()==operation_ && authority_->current(f.grant),"manual-authority");
        // Private reads can cross expiry, revoke, mutate an authored SDK boundary,
        // or reenter. Check again before any further SDK/SQL or private write.
        enabled();f.dispatch.check();
        require(f.session.auth.level()==f.role,"manual-actor-access");
        f.scope.check(request(f.session),db.get(),f.cache->GetConnection());f.dispatch.check();
    }
    std::string connection_id(Frame& f) const {
        check(f);auto q=f.cache->Query("SELECT CONNECTION_ID()");check(f);
        require(q && !q->Eof(),"manual-server-connection");
        auto id=q->AsString(static_cast<size_t>(0));q->Next();
        require(q->Eof() && !id.empty() && id.size()<=20 && id[0]>='1' && id[0]<='9' &&
                id.find_first_not_of("0123456789")==std::string::npos,"manual-server-connection");
        check(f);return id;
    }
    void diagnostic(Frame& f,Checkpoint stage) const {
        check(f);authority_->record(f.request,f.grant,stage);check(f);
    }
    Snapshot inspect(Frame& f) const {
        check(f);auto s=access_->inspect(f.grant.fixture,parse_id(f.request.payment_id),*f.cache);
        check(f);validate_snapshot(s,f.grant);return s;
    }
public:
    Probe(std::shared_ptr<const Authority> a,std::shared_ptr<NativeAccess> n):
        authority_(a),access_(n),operation_(a->operation_id()),process_(getpid()) {
        require(digest(operation_),"manual-operation");
    }
    void activate() {active_.store(true);}
    void hold() noexcept {active_.store(false);}
    void execute(isp_api::Session& session) {
        enabled();DispatchGuard dispatch;
        require(session.auth.level()>=mgr_access::lvAdmin,"manual-actor-access");
        auto r=request(session);dispatch.check();
        require(r.action==ACTION && r.run_id==operation_ && digest(r.nonce),"manual-request");
        phase_for(r.case_id);int payment=parse_id(r.payment_id);
        require(authority_->owns(payment),"manual-outside-registry");
        auto g=authority_->authorize(r);dispatch.check();enabled();validate(r,g,operation_);
        {
            std::lock_guard<std::mutex> lock(mutex_);
            if(fixture_)require(same_fixture(*fixture_,g.fixture) && actor_==r.actor,"manual-registry-change");
            else {fixture_=std::make_shared<const Fixture>(g.fixture);actor_=r.actor;}
            auto key=r.case_id+":"+std::to_string(static_cast<int>(r.slot));
            require(slots_.count(key)==0 && nonces_.count(r.nonce)==0,"manual-reused-intent");
            slots_.insert(key);nonces_.insert(r.nonce);
        }
        require(bool(db),"manual-cache");auto cache=db;auto connection=cache->GetConnection();dispatch.check();
        ManualScope scope(r,g,cache.get(),connection);
        Frame f{this,session,dispatch,scope,r,g,session.auth.level(),cache,connection,"",{},{},{},false};
        FrameGuard frame_guard(f);check(f);
        f.server=connection_id(f);authority_->record_connection(r,g,f.server);check(f);
        f.before=inspect(f);
        bool second=r.case_id=="M4" && r.slot==CallerSlot::Second;
        if(second) {
            auto original=authority_->first_result(g);check(f);validate_snapshot(original.snapshot,g);
            require(digest(original.originals_hash) && original.snapshot.status==4 &&
                    same_snapshot(original.snapshot,f.before),"manual-first-result");
        } else require(f.before.status==1,"manual-preimage-status");
        require(connection_id(f)==f.server,"manual-server-connection");
        diagnostic(f,Checkpoint::Preflight);diagnostic(f,Checkpoint::NativeEntered);
        scope.enter_native();
        // This overload explicitly takes the original supplied Session/Authen.
        auto result=isp_api::InternalCall(session,"payment.setpaid","elid="+r.payment_id);
        // A native exception bypasses this line and remains unchanged. If the
        // handler swallowed our callback failure, preserve that exact failure
        // instead of releasing an apparent success from this qualification.
        if(f.callback_failure)std::rethrow_exception(f.callback_failure);
        check(f);require(f.seen || second,"manual-missing-callback");
        auto after=inspect(f);
        require(same_snapshot(after,f.seen?f.observed:f.before),"manual-after-native");
        require(connection_id(f)==f.server,"manual-server-connection");check(f);
        session.xml=result; // No generated success/duplicate XML or provider ACK.
    }
    void changed(int old_status,int new_status,int payment) {
        // Membership is an immutable, memory-only authority operation.
        if(!authority_->owns(payment))return;
        enabled();require(frame && frame->probe==this && ManualScope::current()==&frame->scope,"manual-missing-context");
        Frame& f=*frame;
        // Reserve before calling GetConnection, authority, readers or diagnostics.
        auto token=f.scope.consume(old_status,new_status,payment,db.get(),f.connection);
        check(f);f.seen=true;
        require(connection_id(f)==f.server,"manual-server-connection");
        auto observed=inspect(f);
        if(token.duplicate) {
            require(f.request.slot==CallerSlot::Second && f.before.status==4 &&
                    same_snapshot(observed,f.before),"manual-duplicate-delta");
            f.observed=observed;return;
        }
        require(f.before.status==1 && observed.status==4 &&
                money_units(observed.balance)-money_units(f.before.balance)==100,"manual-native-delta");
        f.observed=observed;diagnostic(f,Checkpoint::BusinessObserved);
        if(gate_phase(f)==Phase::BeforeJournal)throw ProbeError("manual-injected-before-journal");
        check(f);f.cache->Prepare(JOURNAL).Execute(f.request.run_id,f.request.case_id,
                                                f.request.case_id,f.grant.fixture.facts_hash);
        check(f);diagnostic(f,Checkpoint::JournalReturned);
        if(gate_phase(f)==Phase::AfterJournal)throw ProbeError("manual-injected-after-journal");
    }
    void callback(int old_status,int new_status,int payment) {
        try {
            try {changed(old_status,new_status,payment);}
            catch(const ProbeError& e) {throw mgr_err::Error(e.what());}
        } catch(...) {
            // Capture only on the owning lexical frame. A foreign worker has
            // no frame and never receives the owner's Session/Cache pointer.
            if(frame && frame->probe==this && ManualScope::current()==&frame->scope &&
               !frame->callback_failure)frame->callback_failure=std::current_exception();
            throw;
        }
    }
private:
    Phase gate_phase(Frame& f) const {check(f);return f.grant.phase;}
};
class OwnAction:public isp_api::Action {
    const std::shared_ptr<Probe> probe_;
public:
    explicit OwnAction(std::shared_ptr<Probe> p):Action(ACTION,mgr_access::AccessAdmin),probe_(p) {}
    void Execute(isp_api::Session& s) const override {
        try {probe_->execute(s);}catch(const ProbeError& e) {throw mgr_err::Error(e.what());}
    }
};
enum class Registration {Cold,Registering,Active,Held};
std::atomic<Registration> registration{Registration::Cold};
}
void RegisterManualProbe(std::shared_ptr<const manual::Authority> authority,
                         std::shared_ptr<manual::NativeAccess> access) {
    using manual::require;
    require(bool(authority) && bool(access),"manual-native-dependencies-unavailable");
    Registration expected=Registration::Cold;
    if(!registration.compare_exchange_strong(expected,Registration::Registering)) {
        if(expected==Registration::Registering)registration.store(Registration::Held);
        throw ProbeError("manual-registration-reentry");
    }
    std::shared_ptr<Probe> probe;
    try {
        require(isp_api::HasAction("payment.setpaid") && !isp_api::HasAction(ACTION),"manual-action-registration");
        probe=std::make_shared<Probe>(authority,access);
        // Store has no removal API. Its owning closure retains held state even
        // if a later action registration fails; recovery must retire the process.
        callback::Store<event::PaymentStatusChanged>().Add([probe](int old_s,int new_s,int id) {
            probe->callback(old_s,new_s,id);
        });
        new OwnAction(probe);
        expected=Registration::Registering;
        require(registration.compare_exchange_strong(expected,Registration::Active),"manual-registration-held");
        probe->activate();
    } catch(...) {
        if(probe)probe->hold();registration.store(Registration::Held);throw;
    }
}
}
