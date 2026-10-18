// Actual file/issuer logic; authored boot/image-owner/target facts only.
#include <unistd.h>
#include <cerrno>
static int sync_count=0,fail_sync_at=0;
#include <functional>
static std::function<void()> sync_hook;
int native_test_fsync(int fd) {
    if(++sync_count==fail_sync_at){errno=EIO;return -1;}
    int result=::fsync(fd);if(sync_hook)sync_hook();return result;
}
#define fsync native_test_fsync
#define PAYMENTER_QA_ISSUER_NO_MAIN
#include "../native/control_issuer.cpp"
#undef fsync
#include <iostream>
#include <fstream>
using namespace paymenter_qa;
void check(bool v) {if(!v)throw std::runtime_error("test assertion");}
template<class F> void refuses(F f) {bool failed=false;try{f();}catch(const ProbeError&){failed=true;}check(failed);}
int main(int argc,char**argv) {
    try {
        check(argc==3);std::string scenario=argv[1],root=argv[2];
        auto dir=std::make_shared<PrivateDirectory>(root,Owner{getuid(),getgid()});
        bool target=true;int target_checks=0;
        auto images=[&](const std::string& path){return dir->read(path.substr(path.rfind('/')+1),16777216);};
        NativeControlIssuer issuer(dir,[]{return "11111111-1111-1111-1111-111111111111";},images,
            [&](pid_t pid,const std::string& start,const Fields&){
                check(pid==2 && start=="99");++target_checks;
                return target && !(scenario=="target-late-change" && target_checks>=3);
            });
        if(scenario=="sync-failure")fail_sync_at=5;
        if(scenario=="unseal-sync-failure")fail_sync_at=7;
        if(scenario=="changed-approval") {
            sync_hook=[&]{if(sync_count==2){std::ofstream out(root+"/approval.native.original");out<<"changed original\n";}};
            refuses([&]{issuer.issue_authority();});
            check(dir->exists("revoked.v1") && !dir->exists("authority.v1"));return 0;
        }
        bool authority_bad=scenario=="prepare-only" || scenario=="bad-registry" || scenario=="wrong-image" ||
            scenario=="bad-boot" || scenario=="expired" || scenario=="reused-nonce" ||
            scenario=="proposal-json" || scenario=="wrong-build";
        if(authority_bad){refuses([&]{issuer.issue_authority();});check(!dir->exists("authority.v1"));return 0;}
        if(scenario=="sync-failure" || scenario=="unseal-sync-failure"){
            refuses([&]{issuer.issue_authority();});check(dir->exists("revoked.v1") && dir->exists("authority.v1"));return 0;
        }
        issuer.issue_authority();check(!dir->exists("revoked.v1"));
        auto original=dir->read("authority.v1",4096);
        if(scenario=="authority-reuse"){
            refuses([&]{issuer.issue_authority();});check(same_file(original,dir->read("authority.v1",4096)));return 0;
        }
        if(scenario=="authority-valid")return 0;
        if(scenario=="target-late-change"){
            refuses([&]{issuer.issue_case("F1");});check(dir->exists("revoked.v1") && dir->exists("intent.v1"));return 0;
        }
        if(scenario=="case-sync-failure") {
            fail_sync_at=sync_count+5;refuses([&]{issuer.issue_case("F1");});
            check(dir->exists("revoked.v1") && dir->exists("intent.F1.original") && dir->exists("intent.v1"));return 0;
        }
        if(scenario=="target-refused")target=false;
        if(scenario=="case-nonce" || scenario=="case-expired" || scenario=="case-unbounded" || scenario=="target-refused"){
            refuses([&]{issuer.issue_case("F1");});check(!dir->exists("intent.v1"));return 0;
        }
        if(scenario=="f5"){refuses([&]{issuer.issue_case("F5");});return 0;}
        if(scenario=="existing-intent"){
            dir->create("intent.v1","previous original\n");auto before=dir->read("intent.v1",4096);
            refuses([&]{issuer.issue_case("F1");});check(same_file(before,dir->read("intent.v1",4096)));return 0;
        }
        issuer.issue_case("F1");check(!dir->exists("revoked.v1"));
        auto intent=dir->read("intent.v1",4096);
        check(intent.raw==dir->read("intent.F1.original",4096).raw);
        auto f=parse(intent.raw,"paymenter-source-qa-intent-v1","run case nonce facts pid start issued expires");
        check(f.at("case")=="F1" && f.at("pid")=="2" && f.at("start")=="99");
        if(scenario=="case-reuse"){
            refuses([&]{issuer.issue_case("F1");});check(same_file(intent,dir->read("intent.v1",4096)));
        }
        return 0;
    }catch(const std::exception&e){std::cerr<<e.what()<<'\n';return 1;}
}
