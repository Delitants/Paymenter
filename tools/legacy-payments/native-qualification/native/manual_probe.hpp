#pragma once
#include "manual_contract.hpp"
namespace paymenter_qa {
// Opt-in only. No public/HTTP factory, module entry, live issuer or NativeAccess
// implementation is supplied. Call from a separately qualified private bootstrap.
void RegisterManualProbe(std::shared_ptr<const manual::Authority>,
                         std::shared_ptr<manual::NativeAccess>);
}
