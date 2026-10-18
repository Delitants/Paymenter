#include "bootstrap.hpp"
#include <atomic>
#include <unistd.h>
namespace paymenter_qa {
class BootstrapGate:public FrameworkAuthority {
    const std::shared_ptr<const FrameworkAuthority> authority_;
    const std::string run_;
    const int process_;
    std::atomic<bool> enabled_{false};
    void enabled() const {
        if(getpid()!=process_ || !enabled_.load()) throw ProbeError("qa-bootstrap-inactive");
    }
public:
    explicit BootstrapGate(std::shared_ptr<const FrameworkAuthority> authority):
        authority_(authority),run_(authority->operation_id()),process_(getpid()) {
        if(run_.size()!=64 || run_.find_first_not_of("0123456789abcdef")!=std::string::npos)
            throw ProbeError("qa-bootstrap-operation");
    }
    void enable() noexcept {enabled_.store(true);}
    void close() noexcept {enabled_.store(false);}
    std::string operation_id() const override {
        if(getpid()!=process_ || authority_->operation_id()!=run_) throw ProbeError("qa-bootstrap-operation");
        return run_;
    }
    Grant authorize(const Request& r) const override {
        enabled();auto result=authority_->authorize(r);enabled();return result;
    }
    bool current(const Grant& g) const override {
        if(getpid()!=process_ || !enabled_.load()) return false;
        bool result=authority_->current(g);return result && enabled_.load();
    }
    bool barrier_released(const Grant& g) const override {
        enabled();bool result=authority_->barrier_released(g);enabled();return result;
    }
    void record_refusal(const Request& r) const override {
        enabled();authority_->record_refusal(r);enabled();
    }
    void record_checkpoint(const Request& r,const Grant& g,Checkpoint stage) const override {
        enabled();authority_->record_checkpoint(r,g,stage);enabled();
    }
    void record_connection(const Request& r,const Grant& g,const std::string& id) const override {
        enabled();authority_->record_connection(r,g,id);enabled();
    }
};
FrameworkBootstrap::FrameworkBootstrap():process_(getpid()) {}
FrameworkBootstrap::~FrameworkBootstrap() noexcept {hold();}
void FrameworkBootstrap::hold() noexcept {
    if(getpid()!=process_) return;
    try {
        std::lock_guard<std::mutex> lock(mutex_);
        state_=State::Held;if(gate_)gate_->close();
    } catch(...) {}
}
bool FrameworkBootstrap::prepare(const std::string& path) noexcept {
    if(getpid()!=process_) return false;
    try {
        {
            std::lock_guard<std::mutex> lock(mutex_);
            if(state_!=State::Cold) {state_=State::Held;if(gate_)gate_->close();return false;}
            state_=State::Preparing;
        }
        auto authority=OpenProtectedFrameworkAuthority(path);
        if(!authority)throw ProbeError("qa-bootstrap-missing");
        auto gate=std::make_shared<BootstrapGate>(authority);
        std::lock_guard<std::mutex> lock(mutex_);
        if(state_!=State::Preparing) {gate->close();return false;}
        gate_=gate;state_=State::Prepared;return true;
    } catch(...) {hold();return false;}
}
bool FrameworkBootstrap::initialize() noexcept {
    if(getpid()!=process_) return false;
    try {
        std::shared_ptr<BootstrapGate> gate;
        {
            std::lock_guard<std::mutex> lock(mutex_);
            if(state_!=State::Prepared) {state_=State::Held;if(gate_)gate_->close();return false;}
            state_=State::Registering;gate=gate_;
        }
        gate->operation_id();
        // SDK and protected I/O stay outside the bootstrap lock. A reentrant
        // initialization holds the gate, and the final state check refuses it.
        RegisterFrameworkProbe(gate);
        gate->operation_id();
        std::lock_guard<std::mutex> lock(mutex_);
        if(state_!=State::Registering) {gate->close();return false;}
        gate->enable();state_=State::Active;return true;
    } catch(...) {hold();return false;}
}
bool FrameworkBootstrap::active() const noexcept {
    if(getpid()!=process_) return false;
    try {std::lock_guard<std::mutex> lock(mutex_);return state_==State::Active;}
    catch(...) {return false;}
}
}
