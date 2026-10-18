// Opt-in only: compile separately with the exact admitted protected directory.
#include "bootstrap.hpp"
#include <api/module.h>
#ifndef PAYMENTER_QA_CONTROL_DIRECTORY
#error "An exact reviewed protected control directory is required"
#endif
namespace { paymenter_qa::FrameworkBootstrap bootstrap; }
LIBRARY_ENTRY() {
    return bootstrap.prepare(PAYMENTER_QA_CONTROL_DIRECTORY);
}
MODULE_INIT(paymenter_qa_framework,"database") {
    // Failure leaves any partial own registration disabled, without propagating
    // an initialization exception into other SDK modules.
    (void)bootstrap.initialize();
}
