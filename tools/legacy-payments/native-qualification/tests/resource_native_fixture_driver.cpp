// Source-root OS/file-custody fixture ONLY. Database facts are always authored.
// This must never be built with a real MySQL C API or shipped as an operator.
#define PAYMENTER_QA_RESOURCES_NO_MAIN
#include "../native/resource_custody.cpp"
#ifndef PAYMENTER_QA_AUTHORED_MYSQL_BOUNDARY
#error "This fixture requires the authored MySQL boundary, never a real database"
#endif
int main(int argc,char**argv) {
    using namespace paymenter_qa;
    try {
#ifdef __linux__
        guard((argc==3 || argc==5) && geteuid()==0 && getegid()==0,"fixture-linux-root-required");
        auto dir=std::make_shared<PrivateDirectory>(argv[2],Owner{0,0});std::string mode=argv[1];
        ResourceHooks hooks{Owner{0,0},boot_id,[](const std::string& path){return image(path,true);},resource_native_runtime,resource_native_retired,real("/proc/self/exe"),process_start};
        test_resource_mode=true;test_rows=false;test_mode="source-root-authored-sql-fixture";
        NativeQaResourceFactory factory(dir,hooks);
        if(mode=="create" && argc==3)factory.create(dir->path()+"/credential","qa","/fictional.sock");
        else if(mode=="check-live" && argc==3){
            OwnedQaResources owned(dir);guard(!resource_native_retired(static_cast<pid_t>(number(owned.created.at("manager_pid"))),owned.created.at("manager_start")),"fixture-live-peer-not-present");
        }else if(mode=="prepare-cleanup" && argc==5){
            OwnedQaResources owned(dir);resource_pid(argv[3]);number(argv[4]);
            guard(resource_native_retired(static_cast<pid_t>(number(owned.created.at("manager_pid"))),owned.created.at("manager_start")),"fixture-old-peer-still-live");
            guard(resource_native_runtime(static_cast<pid_t>(number(argv[3])),argv[4],owned.authority,number(owned.created.at("module_dev")),number(owned.created.at("module_ino"))),"fixture-replacement-peer");
            dir->create("revoked.v1","source-root-fixture-revocation-not-sdk-or-database-proof\n");
            auto revoked=dir->read("revoked.v1",4096),created=owned.created_original(),complete=owned.complete_original();const auto now=now_seconds();
            Fields approval{{"intent","retire-owned-qa"},{"run",owned.created.at("run")},{"boot",boot_id()},{"issued",std::to_string(now)},{"expires",std::to_string(now+25)},
                {"created_sha256",hash(created.raw)},{"created_identity",resource_identity(created)},{"revoked_sha256",hash(revoked.raw)},{"revoked_identity",resource_identity(revoked)},
                {"manager_pid",argv[3]},{"manager_start",argv[4]},{"complete_sha256",hash(complete.raw)},{"complete_identity",resource_identity(complete)}};
            dir->create("qa.cleanup.approval.original",encode_fields("paymenter-source-qa-cleanup-approval-v1",approval,CLEANUP_FIELDS));
        }else if(mode=="retire" && argc==3){
            // Recreate only the authored boundary's fictional tables. There is
            // no real DB, socket, persistence or native transaction underneath.
            test_resource_tables={{true,true}};
            guard(factory.retire(dir->path()+"/credential","qa","/fictional.sock").complete,"fixture-retirement-incomplete");
        }else throw ProbeError("fixture-mode");
        std::cout<<"source-root-fixture-retained-authored-sql-only\n";return 0;
#else
        (void)argc;(void)argv;throw ProbeError("fixture-linux-root-required");
#endif
    }catch(const ProbeError& e){std::cerr<<e.what()<<'\n';}catch(...){std::cerr<<"fixture-incomplete\n";}
    return 1;
}
