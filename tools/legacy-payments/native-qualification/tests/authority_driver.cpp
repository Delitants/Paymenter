// Actual bridge/file logic, with a fictional runtime check. No native acceptance.
// Including the implementation gives tests access to internal custody helpers;
// no test owner/runtime override is exported by the production header/factory.
#include <unistd.h>
#include <cerrno>
static bool fail_sync=false;
int bridge_test_fsync(int fd) { if (fail_sync) { errno=EIO; return -1; } return ::fsync(fd); }
#define fsync bridge_test_fsync
#include "../native/authority_bridge.cpp"
#undef fsync
#include <fstream>
#include <iostream>
#include <atomic>
#include <thread>
#include <sys/wait.h>

using namespace paymenter_qa;
void check(bool value) { if (!value) throw std::runtime_error("test assertion"); }
template<class F> void refuses(F f) {
    bool failed=false;
    try { f(); } catch (const ProbeError&) { failed=true; }
    check(failed);
}
std::string contents(const std::string& path) {
    std::ifstream in(path); return std::string(std::istreambuf_iterator<char>(in),{});
}
void put(const std::string& path, const std::string& raw) {
    std::ofstream out(path); out<<raw; out.close(); check(bool(out)); check(chmod(path.c_str(),0600)==0);
}
void replace_value(std::string& raw, const std::string& from, const std::string& to) {
    auto at=raw.find(from); check(at!=std::string::npos); raw.replace(at,from.size(),to);
}
int main(int argc, char** argv) {
    try {
        check(argc==3); std::string scenario=argv[1], root=argv[2];
        if(scenario.find("batch-")==0) {
            auto dir=std::make_shared<PrivateDirectory>(root,Owner{getuid(),getgid()});
            if(scenario=="batch-symlink"){check(rename((root+"/registry.original").c_str(),(root+"/registry.old").c_str())==0);check(symlink("registry.old",(root+"/registry.original").c_str())==0);}
            if(scenario=="batch-writable")check(chmod((root+"/registry.original").c_str(),0666)==0);
            std::vector<std::pair<std::string,size_t>> names{{"authority.v1",4096},{"registry.original",scenario=="batch-bound"?1u:4096u}};
            if(scenario=="batch-valid"){auto files=dir->read_batch(names);check(files.size()==2 && same_file(files[0],dir->read("authority.v1",4096)));}
            else refuses([&]{dir->read_batch(names);});return 0;
        }
        auto intent=contents(root+"/intent.v1");
        replace_value(intent,"pid=PID\n","pid="+std::to_string(getpid())+"\n");
        if (scenario=="wrong-pid") replace_value(intent,"pid="+std::to_string(getpid())+"\n","pid=1\n");
        if (scenario=="wrong-start") replace_value(intent,"start=99\n","start=100\n");
        if (scenario=="wrong-facts") replace_value(intent,"facts="+std::string(64,'c'),"facts="+std::string(64,'f'));
        if (scenario=="wrong-intent-nonce") replace_value(intent,"nonce="+std::string(64,'b'),"nonce="+std::string(64,'f'));
        put(root+"/intent.v1",intent);
        if (scenario=="authority-symlink") {
            check(rename((root+"/authority.v1").c_str(),(root+"/other").c_str())==0);
            check(symlink("other",(root+"/authority.v1").c_str())==0);
        }
        if (scenario=="authority-hardlink") check(link((root+"/authority.v1").c_str(),(root+"/other").c_str())==0);
        if (scenario=="authority-mode") check(chmod((root+"/authority.v1").c_str(),0644)==0);
        if (scenario=="directory-mode") check(chmod(root.c_str(),0755)==0);
        if (scenario=="public-factory-refuses") {
            refuses([&]{OpenProtectedFrameworkAuthority(root);}); return 0;
        }
        auto make=[&] {
            auto dir=std::make_shared<PrivateDirectory>(root,Owner{getuid(),getgid()});
            auto authority=dir->read("authority.v1",4096);
            return std::make_shared<ProtectedAuthority>(dir,authority,"99",[]{return true;});
        };
        if (scenario=="prepare-only" || scenario=="unknown-field" || scenario=="duplicate-field" ||
            scenario=="expired" || scenario=="future" || scenario=="unbounded" ||
            scenario=="authority-symlink" || scenario=="authority-hardlink" ||
            scenario=="authority-mode" || scenario=="directory-mode" || scenario=="reused-nonces" || scenario=="registry-hash") {
            refuses(make); return 0;
        }
        auto dir=std::make_shared<PrivateDirectory>(root,Owner{getuid(),getgid()});
        std::atomic<bool> runtime{true},pause{false},entered{false},release{false};
        pid_t parent=getpid();
        auto issued=intent.find("issued="); check(issued!=std::string::npos);
        uint64_t wall=std::stoull(intent.substr(issued+7));
        auto bridge=std::make_shared<ProtectedAuthority>(dir,dir->read("authority.v1",4096),"99",[&]{
            if (pause && getpid()==parent) {
                entered=true;
                while (!release) std::this_thread::sleep_for(std::chrono::milliseconds(1));
            }
            return runtime.load();
        },[&]{return wall;});
        Request request{reinterpret_cast<void*>(1),"paymenter.qa.transaction","fictional-admin",
                        std::string(64,'a'),"F4",std::string(64,'b')};
        if (scenario=="wrong-actor") request.actor="other-admin";
        if (scenario=="wrong-action") request.action="payment.setpaid";
        if (scenario=="wrong-run") request.run_id=std::string(64,'f');
        if (scenario=="wrong-nonce") request.nonce=std::string(64,'f');
        if (scenario=="wrong-case") request.case_id="F5";
        if (scenario=="empty-identity") request.identity=nullptr;
        if (scenario=="wrong-intent-nonce") request.nonce=std::string(64,'f');
        if (scenario=="intent-mode") check(chmod((root+"/intent.v1").c_str(),0644)==0);
        if (scenario=="wrong-actor" || scenario=="wrong-action" || scenario=="wrong-run" ||
            scenario=="wrong-nonce" || scenario=="wrong-case" || scenario=="wrong-pid" ||
            scenario=="wrong-start" || scenario=="wrong-facts" || scenario=="intent-mode" ||
            scenario=="empty-identity" || scenario=="wrong-intent-nonce") {
            refuses([&]{bridge->authorize(request);}); return 0;
        }
        auto grant=bridge->authorize(request);
        check(grant.mode==Mode::ObserveBeforeReturn && grant.actor=="fictional-admin");
        check(bridge->current(grant));
        if(scenario.find("refusal-")==0) {
            if(scenario!="refusal-with-intent")check(unlink((root+"/intent.v1").c_str())==0);
            request.case_id="F5";request.nonce=hash(dir->read("authority.v1",4096).raw+"\nF5\n");
            if(scenario=="refusal-wrong-nonce")request.nonce=std::string(64,'f');
            if(scenario=="refusal-wrong-nonce" || scenario=="refusal-with-intent")refuses([&]{bridge->record_refusal(request);});
            else {bridge->record_refusal(request);check(dir->exists("refusal.F5.original"));if(scenario=="refusal-replay")refuses([&]{bridge->record_refusal(request);});}
            check(!dir->exists("writer.F5.original"));return 0;
        }
        if(scenario.find("checkpoint-")==0) {
            if(scenario=="checkpoint-no-writer"){refuses([&]{bridge->record_checkpoint(request,grant,Checkpoint::BusinessReturned);});return 0;}
            bridge->record_connection(request,grant,"21");
            if(scenario=="checkpoint-order"){refuses([&]{bridge->record_checkpoint(request,grant,Checkpoint::JournalReturned);});return 0;}
            if(scenario=="checkpoint-wrong-session"){auto wrong=request;wrong.identity=reinterpret_cast<void*>(2);refuses([&]{bridge->record_checkpoint(wrong,grant,Checkpoint::BusinessReturned);});return 0;}
            if(scenario=="checkpoint-sync-failure"){fail_sync=true;refuses([&]{bridge->record_checkpoint(request,grant,Checkpoint::BusinessReturned);});fail_sync=false;check(!bridge->current(grant));return 0;}
            bridge->record_checkpoint(request,grant,Checkpoint::BusinessReturned);check(dir->exists("checkpoint.F4.business.original"));
            if(scenario=="checkpoint-duplicate")refuses([&]{bridge->record_checkpoint(request,grant,Checkpoint::BusinessReturned);});
            if(scenario=="checkpoint-replace") {auto original=dir->read("checkpoint.F4.business.original",4096);check(rename((root+"/checkpoint.F4.business.original").c_str(),(root+"/checkpoint.old").c_str())==0);dir->create("checkpoint.F4.business.original",original.raw);refuses([&]{bridge->record_checkpoint(request,grant,Checkpoint::JournalReturned);});}
            if(scenario=="checkpoint-journal"){bridge->record_checkpoint(request,grant,Checkpoint::JournalReturned);bridge->record_checkpoint(request,grant,Checkpoint::BarrierEntered);check(dir->exists("checkpoint.F4.barrier.original"));}
            return 0;
        }
        if (scenario=="clock-forward") { wall+=25; check(!bridge->current(grant)); return 0; }
        if (scenario=="clock-backward") { --wall; check(!bridge->current(grant)); return 0; }
        if (scenario=="forged-grant") {
            auto forged=grant; forged.facts_hash=std::string(64,'f'); check(!bridge->current(forged));
            forged=grant; forged.expires+=std::chrono::seconds(1); check(!bridge->current(forged)); return 0;
        }
        if (scenario=="reused-case") { refuses([&]{bridge->authorize(request);}); return 0; }
        if (scenario=="wrong-grant-thread") {
            bool accepted=true;
            std::thread other([&]{accepted=bridge->current(grant);}); other.join();
            check(!accepted); return 0;
        }
        if (scenario=="forked-locked") {
            pause=true;
            std::thread other([&]{bridge->operation_id();});
            while (!entered) std::this_thread::sleep_for(std::chrono::milliseconds(1));
            pid_t child=fork(); check(child>=0);
            if (!child) { alarm(1); _exit(bridge->current(grant)?1:0); }
            release=true; other.join(); int status=0;
            check(waitpid(child,&status,0)==child && WIFEXITED(status) && WEXITSTATUS(status)==0); return 0;
        }
        if (scenario=="intent-replaced" || scenario=="authority-replaced") {
            std::string name=scenario=="intent-replaced"?"intent.v1":"authority.v1";
            put(root+"/new",contents(root+"/"+name));
            check(rename((root+"/new").c_str(),(root+"/"+name).c_str())==0);
            check(!bridge->current(grant)); return 0;
        }
        if (scenario=="registry-changed" || scenario=="build-changed") {
            put(root+(scenario=="registry-changed"?"/registry.original":"/build.original"),"changed\n");
            check(!bridge->current(grant)); return 0;
        }
        if (scenario=="revoked") { put(root+"/revoked.v1","revoked\n"); check(!bridge->current(grant)); return 0; }
        if (scenario=="runtime-revoked") { runtime=false; check(!bridge->current(grant)); return 0; }
        if (scenario=="directory-replaced") {
            std::string moved=root+".moved";
            check(rename(root.c_str(),moved.c_str())==0);
            check(mkdir(root.c_str(),0700)==0); check(!bridge->current(grant));
            check(rmdir(root.c_str())==0); check(rename(moved.c_str(),root.c_str())==0); return 0;
        }
        if (scenario=="forked") {
            pid_t child=fork(); check(child>=0);
            if (!child) _exit(bridge->current(grant)?1:0);
            int status=0; check(waitpid(child,&status,0)==child && WIFEXITED(status) && WEXITSTATUS(status)==0); return 0;
        }
        if (scenario=="release-absent") {
            check(unlink((root+"/release.F4.v1").c_str())==0); check(!bridge->barrier_released(grant)); return 0;
        }
        if (scenario=="release-wrong-nonce" || scenario=="release-wrong-original") {
            refuses([&]{bridge->barrier_released(grant);}); return 0;
        }
        if (scenario=="release-valid") { check(bridge->barrier_released(grant)); return 0; }
        if (scenario=="release-file-mode") {
            check(chmod((root+"/release.F4.v1").c_str(),0644)==0);
            refuses([&]{bridge->barrier_released(grant);}); return 0;
        }
        if (scenario=="writer-symlink") {
            put(root+"/untouched","private original\n");
            check(symlink("untouched",(root+"/writer.F4.original").c_str())==0);
            refuses([&]{bridge->record_connection(request,grant,"123");});
            check(contents(root+"/untouched")=="private original\n"); return 0;
        }
        if (scenario=="writer-connection") {
            refuses([&]{bridge->record_connection(request,grant,"0123");});
            check(access((root+"/writer.F4.original").c_str(),F_OK)!=0); return 0;
        }
        if (scenario=="long-connection") {
            refuses([&]{bridge->record_connection(request,grant,"18446744073709551616");}); return 0;
        }
        if (scenario=="wrong-diagnostic-binding") {
            auto changed=request; changed.nonce=std::string(64,'f');
            refuses([&]{bridge->record_connection(changed,grant,"123");});
            check(access((root+"/writer.F4.original").c_str(),F_OK)!=0); return 0;
        }
        if (scenario=="wrong-diagnostic-session") {
            auto changed=request; changed.identity=reinterpret_cast<void*>(2);
            refuses([&]{bridge->record_connection(changed,grant,"123");});
            check(access((root+"/writer.F4.original").c_str(),F_OK)!=0); return 0;
        }
        if (scenario=="sync-failure") {
            fail_sync=true; refuses([&]{bridge->record_connection(request,grant,"123");});
            check(contents(root+"/writer.F4.original").find("connection=123\n")!=std::string::npos);
            check(!bridge->current(grant)); return 0;
        }
        bridge->record_connection(request,grant,"123");
        auto diagnostic=contents(root+"/writer.F4.original");
        check(diagnostic.find("connection=123\n")!=std::string::npos);
        check(diagnostic.find("nonce="+std::string(64,'b')+"\n")!=std::string::npos);
        if (scenario=="duplicate-diagnostic") {
            refuses([&]{bridge->record_connection(request,grant,"456");});
            check(contents(root+"/writer.F4.original")==diagnostic); check(!bridge->current(grant));
        }
        return 0;
    } catch (const std::exception& e) { std::cerr<<e.what()<<'\n'; return 1; }
}
