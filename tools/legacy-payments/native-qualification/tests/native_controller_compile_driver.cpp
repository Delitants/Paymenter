#define PAYMENTER_QA_CONTROLLER_NO_MAIN
#include "../native/run_controller.cpp"
#include <iostream>
int main(int argc,char**argv) {
    using namespace paymenter_qa;
    if(argc!=2)return 2;
    try {
        auto dir=std::make_shared<PrivateDirectory>(argv[1],Owner{getuid(),getgid()});
        ControllerHooks hooks{Owner{getuid(),getgid()},[]{return "fictional";},[](const std::string&){throw ProbeError("no-image");return File{};},
            [](pid_t,const std::string&,const Fields&){return false;},[](pid_t,const std::string&){return false;},"/fictional/controller",[]{return "99";},
            [](const NativeChildCommand&,Clock::time_point,const std::function<void()>&){throw ProbeError("no-launch");return NativeChildResult{};}};
        NativeFrameworkBackend backend(dir,hooks);return 3;
    }catch(const ProbeError&){return 0;}
}
