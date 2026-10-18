// Authored entry boundary only; this is not SDK loading/lifecycle emulation.
#pragma once
extern "C" bool lib_init();
#define LIBRARY_ENTRY bool lib_init
#define MODULE_INIT(NAME,DEPEND) void paymenter_qa_test_module_initialize()
