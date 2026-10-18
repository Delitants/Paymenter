// Generic QA bridge contract. No private registry reader or live issuer here.
#pragma once
#include <chrono>
#include <memory>
#include <stdexcept>
#include <string>

namespace paymenter_qa {
class ProbeError: public std::runtime_error {
public:
    explicit ProbeError(const char* code): std::runtime_error(code) {}
};
enum class Mode { Normal, ThrowAfterBusiness, ThrowAfterJournal, ObserveBeforeReturn };
struct Request {
    const void* identity;
    std::string action, actor, run_id, case_id, nonce;
};
struct Grant {
    std::string run_id, case_id, nonce, actor, facts_hash;
    Mode mode;
    std::chrono::steady_clock::time_point expires;
};
class FrameworkAuthority {
public:
    virtual ~FrameworkAuthority() {}
    // Implement only in an admitted, process-bound private native bridge. These
    // methods must enforce protected original registry custody, boot/build/actor
    // identity and current operation scope. Profiles/boolean review labels cannot
    // implement them. Reads and the private QA diagnostic write must be bounded;
    // none may execute SQL, perform network I/O or reenter framework callbacks.
    virtual std::string operation_id() const = 0;
    virtual Grant authorize(const Request&) const = 0;
    virtual bool current(const Grant&) const = 0;
    virtual bool barrier_released(const Grant&) const = 0;
    // Retain this diagnostic only in the admitted source-private evidence file.
    // It describes a connection, never a transaction, commit or settlement.
    virtual void record_connection(const Request&, const Grant&, const std::string&) const = 0;
};

// Call only from a separately qualified SDK MODULE_INIT after its private native
// issuer has admitted the exact operation. No automatic module entry is shipped.
// The SDK owns the action/event; this function does not load/unload the library.
void RegisterFrameworkProbe(std::shared_ptr<const FrameworkAuthority> authority);
}
