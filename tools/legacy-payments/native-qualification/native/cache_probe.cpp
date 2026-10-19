// Test-only own action. This is not a native payment handler or financial journal.
#include "cache_probe.hpp"
#include <api/action.h>
#include <mgr/mgrdb.h>
#include <mgr/mgrdb_struct.h>
#include <mgr/mgrerr.h>
#include <map>
#include <mutex>
#include <set>
#include <thread>
#include <unistd.h>

// Supplied BILLmanager cache; never construct an independent cache/connection.
extern std::shared_ptr<mgr_db::Cache> db;

namespace paymenter_qa {
namespace {
using Clock = std::chrono::steady_clock;
const char* const ACTION = "paymenter.qa.transaction";
const char* const BUSINESS = "INSERT INTO paymenter_qa_business_v1 (run_id,case_id,amount,facts_hash) VALUES (?,?,?,?)";
const char* const JOURNAL = "INSERT INTO paymenter_qa_journal_v1 (run_id,case_id,business_case_id,facts_hash) VALUES (?,?,?,?)";

void require(bool condition, const char* code) {
    if (!condition) throw ProbeError(code);
}
bool digest(const std::string& value) {
    if (value.size()!=64) return false;
    for (char c: value) if (!((c>='0' && c<='9') || (c>='a' && c<='f'))) return false;
    return true;
}
Mode mode_for(const std::string& case_id) {
    if (case_id=="F1") return Mode::Normal;
    if (case_id=="F2") return Mode::ThrowAfterBusiness;
    if (case_id=="F3") return Mode::ThrowAfterJournal;
    if (case_id=="F4") return Mode::ObserveBeforeReturn;
    // F5 consists of refused requests; it never authorizes a write phase.
    throw ProbeError("qa-case-refused");
}
std::string server_connection(const std::shared_ptr<mgr_db::Cache>& cache) {
    auto query=cache->Prepare("SELECT CONNECTION_ID()").Execute();
    require(query && !query->Eof(), "qa-connection-diagnostic");
    std::string value=query->AsString(static_cast<size_t>(0));
    query->Next();
    require(query->Eof() && !value.empty() && value.size()<=20 && value[0]>='1' &&
            value[0]<='9', "qa-connection-diagnostic");
    for (char c: value) require(c>='0' && c<='9', "qa-connection-diagnostic");
    return value;
}
Request request(isp_api::Session& session) {
    return {&session, session.action.name(), session.auth.name(),
            session.Param("qa_run"), session.Param("qa_case"), session.Param("qa_nonce")};
}

class Probe {
    struct Context {
        Request request;
        Grant grant;
        std::shared_ptr<mgr_db::Cache> cache;
        mgr_db::Connection* connection;
        std::string server_connection_id;
        std::thread::id thread;
        Clock::time_point deadline;
        bool business_done, after_started;
    };
    std::shared_ptr<const FrameworkAuthority> authority_;
    const std::string operation_;
    const pid_t process_;
    std::mutex mutex_;
    std::map<const void*, std::shared_ptr<Context>> contexts_;
    std::set<std::string> consumed_cases_;

    void validate(const Request& r, const Grant& g) const {
        require(getpid()==process_, "qa-process");
        require(r.identity && r.action==ACTION && r.actor.size()>0 && r.actor.size()<=128 &&
                digest(r.run_id) && digest(r.nonce), "qa-request");
        require(r.run_id==operation_ && g.run_id==operation_ &&
                g.case_id==r.case_id && g.nonce==r.nonce && g.actor==r.actor &&
                digest(g.facts_hash) && g.mode==mode_for(r.case_id), "qa-binding");
        auto now=Clock::now();
        require(g.expires>now && g.expires-now<=std::chrono::seconds(600), "qa-expiry");
        require(authority_->operation_id()==operation_ && authority_->current(g), "qa-registry");
        // Protected reads can cross expiry; recheck after calling the bridge.
        require(Clock::now()<g.expires, "qa-expiry");
    }
    void bind_context(const Request& r, const Context& c) const {
        require(getpid()==process_, "qa-process");
        require(r.identity==c.request.identity && r.action==c.request.action &&
                r.actor==c.request.actor && r.run_id==c.request.run_id &&
                r.case_id==c.request.case_id && r.nonce==c.request.nonce &&
                c.thread==std::this_thread::get_id(), "qa-context");
    }
    void validate_case_authority(const Request& r, const Context& c) const {
        bind_context(r,c);
        validate(r,c.grant);
        require(Clock::now()<c.deadline, "qa-case-deadline");
    }
    void validate_context(const Request& r, const Context& c) const {
        validate_case_authority(r,c);
        require(db && db.get()==c.cache.get() &&
                c.cache->GetConnection()==c.connection, "qa-cache-connection");
        require(server_connection(c.cache)==c.server_connection_id, "qa-server-connection");
        validate_case_authority(r,c);
    }
    std::shared_ptr<Context> context(const void* identity) {
        std::lock_guard<std::mutex> lock(mutex_);
        auto found=contexts_.find(identity);
        require(found!=contexts_.end(), "qa-missing-context");
        return found->second;
    }
    void barrier(const Request& r, const Context& c) {
        // Only this own framework action waits. No barrier is in a payment path.
        auto end=Clock::now()+std::chrono::seconds(5);
        if (c.deadline<end) end=c.deadline;
        if (c.grant.expires<end) end=c.grant.expires;
        authority_->record_checkpoint(r,c.grant,Checkpoint::BarrierEntered);
        while (true) {
            validate_case_authority(r,c);
            require(Clock::now()<end, "qa-barrier-timeout");
            bool released=authority_->barrier_released(c.grant);
            validate_case_authority(r,c);
            require(Clock::now()<end, "qa-barrier-timeout");
            if (released) {
                validate_context(r,c);
                require(Clock::now()<end, "qa-barrier-timeout");
                return;
            }
            std::this_thread::sleep_for(std::chrono::milliseconds(10));
        }
    }
public:
    explicit Probe(std::shared_ptr<const FrameworkAuthority> authority):
        authority_(authority), operation_(authority->operation_id()), process_(getpid()) {
        require(digest(operation_), "qa-operation");
    }
    void execute(isp_api::Session& session) {
        require(getpid()==process_, "qa-process");
        require(session.auth.level()>=mgr_access::lvAdmin, "qa-actor-access");
        Request r=request(session);
        require(r.action==ACTION && r.run_id==operation_ && digest(r.nonce), "qa-request");
        if(r.case_id=="F5") {authority_->record_refusal(r);throw ProbeError("qa-guard-case");}
        mode_for(r.case_id);
        Grant g=authority_->authorize(r);
        validate(r,g);
        {
            std::lock_guard<std::mutex> lock(mutex_);
            require(contexts_.size()<2 && contexts_.find(r.identity)==contexts_.end(), "qa-active-context");
            for (const auto& item: contexts_)
                require(item.second->thread!=std::this_thread::get_id(), "qa-nested-context");
            require(consumed_cases_.count(r.case_id)==0, "qa-reused-case");
            auto c=std::make_shared<Context>(Context{r,g,nullptr,nullptr,"",
                std::this_thread::get_id(),Clock::now()+std::chrono::seconds(30),false,false});
            consumed_cases_.insert(r.case_id);
            contexts_.emplace(r.identity,c);
        }
        try {
            auto c=context(r.identity);
            // Reserve the request before entering SDK code, and never hold our
            // mutex across an SDK call: callbacks must refuse, not deadlock.
            require(bool(db), "qa-cache");
            c->cache=db;
            c->connection=c->cache->GetConnection();
            require(c->connection!=nullptr, "qa-connection");
            c->server_connection_id=server_connection(c->cache);
            validate_case_authority(r,*c);
            authority_->record_connection(r,g,c->server_connection_id);
            validate_context(r,*c);
            c->cache->Prepare(BUSINESS).Execute(g.run_id,g.case_id,"0.0100",g.facts_hash);
            validate_context(r,*c);
            authority_->record_checkpoint(r,g,Checkpoint::BusinessReturned);
            validate_context(r,*c);
            c->business_done=true;
            if (g.mode==Mode::ThrowAfterBusiness) {
                authority_->record_checkpoint(r,g,Checkpoint::InjectingBusiness);
                throw ProbeError("qa-injected-after-business");
            }
        } catch (...) {
            finish(r.identity);
            throw;
        }
    }
    void after(isp_api::Session& session) {
        require(session.auth.level()>=mgr_access::lvAdmin, "qa-actor-access");
        Request r=request(session);
        auto c=context(r.identity);
        // A wrong callback cannot retire another thread's owned context.
        bind_context(r,*c);
        {
            std::lock_guard<std::mutex> lock(mutex_);
            require(c->business_done && !c->after_started, "qa-business-phase");
            c->after_started=true;
        }
        try {
            validate_context(r,*c);
            c->cache->Prepare(JOURNAL).Execute(c->grant.run_id,c->grant.case_id,
                                             c->grant.case_id,c->grant.facts_hash);
            validate_context(r,*c);
            authority_->record_checkpoint(r,c->grant,Checkpoint::JournalReturned);
            validate_context(r,*c);
            if (c->grant.mode==Mode::ThrowAfterJournal) {
                authority_->record_checkpoint(r,c->grant,Checkpoint::InjectingJournal);
                throw ProbeError("qa-injected-after-journal");
            }
            if (c->grant.mode==Mode::ObserveBeforeReturn) barrier(r,*c);
            finish(r.identity);
        } catch (...) {
            finish(r.identity);
            throw;
        }
    }
    void finish(const void* identity) noexcept {
        // Memory cleanup only. Final never observes or claims a durable commit.
        try {
            std::lock_guard<std::mutex> lock(mutex_);
            auto found=contexts_.find(identity);
            if (found!=contexts_.end() && found->second->thread==std::this_thread::get_id())
                contexts_.erase(found);
        } catch (...) {}
    }
};

class OwnAction: public isp_api::Action {
    std::shared_ptr<Probe> probe_;
public:
    explicit OwnAction(std::shared_ptr<Probe> p): Action(ACTION,mgr_access::AccessAdmin),probe_(p) {}
    void Execute(isp_api::Session& session) const override {
        try { probe_->execute(session); }
        // Only our own failures need the SDK error representation. Native
        // errors and service/control exceptions must pass through unchanged.
        catch(const ProbeError& error) { throw mgr_err::Error(error.what()); }
    }
};
class OwnAfter: public isp_api::Event {
    std::shared_ptr<Probe> probe_;
public:
    explicit OwnAfter(std::shared_ptr<Probe> p): Event(ACTION,"paymenter.qa.cache.after",epAfter),probe_(p) {}
    void AfterExecute(isp_api::Session& session) const override {
        try { probe_->after(session); }
        catch(const ProbeError& error) { throw mgr_err::Error(error.what()); }
    }
    void Final(isp_api::Session& session) const override { probe_->finish(&session); }
};
}

void RegisterFrameworkProbe(std::shared_ptr<const FrameworkAuthority> authority) {
    require(bool(authority), "qa-native-issuer-unavailable");
    require(!isp_api::HasAction(ACTION), "qa-action-collision");
    auto probe=std::make_shared<Probe>(authority);
    new OwnAction(probe);
    new OwnAfter(probe);
}
}
