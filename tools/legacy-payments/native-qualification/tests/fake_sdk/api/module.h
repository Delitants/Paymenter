// Authored dependency dispatch only; not a vendor SDK or native lifecycle proof.
#pragma once
#include <set>
#include <sstream>
#include <string>
#include <vector>
namespace isp_api {
struct TestModule {
    std::string dependency;
    void (*initialize)();
    bool called;
};
inline std::vector<TestModule>& test_modules() {
    static std::vector<TestModule> modules;
    return modules;
}
struct TestModuleRegistration {
    TestModuleRegistration(const char* dependency, void (*initialize)()) {
        test_modules().push_back({dependency, initialize, false});
    }
};
inline unsigned test_dispatch_ready(const std::set<std::string>& ready) {
    unsigned dispatched=0;
    for(auto& module:test_modules()) {
        if(module.called)continue;
        std::istringstream dependencies(module.dependency);
        std::string dependency;
        bool satisfied=true;
        while(dependencies>>dependency)
            if(!ready.count(dependency))satisfied=false;
        if(!satisfied)continue;
        module.called=true;
        module.initialize();
        ++dispatched;
    }
    return dispatched;
}
}
extern "C" bool lib_init();
#define LIBRARY_ENTRY bool lib_init
#define MODULE_INIT(NAME,DEPEND) \
    void NAME##_test_initialize(); \
    namespace { const isp_api::TestModuleRegistration NAME##_test_registration(DEPEND,NAME##_test_initialize); } \
    void NAME##_test_initialize()
