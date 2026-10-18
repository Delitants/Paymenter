// Guarded SDK startup; public methods have no factory/registration override.
#pragma once
#include "authority_bridge.hpp"
#include <mutex>
namespace paymenter_qa {
class BootstrapGate;
class FrameworkBootstrap {
    enum class State { Cold, Preparing, Prepared, Registering, Active, Held };
    const int process_;
    mutable std::mutex mutex_;
    State state_=State::Cold;
    std::shared_ptr<BootstrapGate> gate_;
    void hold() noexcept;
public:
    FrameworkBootstrap();
    ~FrameworkBootstrap() noexcept;
    FrameworkBootstrap(const FrameworkBootstrap&)=delete;
    FrameworkBootstrap& operator=(const FrameworkBootstrap&)=delete;
    bool prepare(const std::string& protected_directory) noexcept;
    bool initialize() noexcept;
    bool active() const noexcept;
};
}
