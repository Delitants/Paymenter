// Protected, framework-only native control. No automatic entry or Python issuer.
#pragma once
#include "cache_probe.hpp"

namespace paymenter_qa {
// Call only from the separately admitted private MODULE_INIT. The directory is
// an issuer-owned root-private control location, never an HTTP/profile parameter.
// Linux/root only; verifies loaded-module and executable identities itself.
// This does not install resources, establish DB facts or issue an AdmittedRun.
std::shared_ptr<const FrameworkAuthority> OpenProtectedFrameworkAuthority(
    const std::string& operation_directory);
}
