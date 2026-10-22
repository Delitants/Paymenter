#include "../native/owned_child.hpp"
#include "../native/program_image.hpp"
#include <iostream>
using namespace paymenter_qa;
struct OwnedDirectory {std::string path;~OwnedDirectory(){if(!path.empty())rmdir(path.c_str());}};
struct OwnedLink {std::string path;~OwnedLink(){if(!path.empty())unlink(path.c_str());}};
void check(bool v){if(!v)throw std::runtime_error("assertion");}
int main(int argc,char**argv){
    try {
        if((argc==3 || argc==4) && std::string(argv[1])=="child") {
            std::string m=argv[2];
            if((m=="alias-argv0" || m=="alias-relative-argv0")){check(argc==4 && std::string(argv[0])==argv[3]);write(1,"alias-ok",8);return 0;}
            if(m=="capture" || m=="hardlink-capture" || m=="alias-capture"){write(1,"hello",5);write(2,"error",5);return 0;}
            if(m=="nonzero")return 7;
            if(m=="overflow" || m=="stderr-overflow"){std::string s(40000,'z');write(m=="overflow"?1:2,s.data(),s.size());return 0;}
            if(m=="descendant-pipe"){if(fork()==0){usleep(700000);_exit(0);}return 0;}
            if(m=="inherited-limits"){struct rlimit r;check(getrlimit(RLIMIT_CPU,&r)==0 && r.rlim_max<=2);return 0;}
            if(m=="fd-closed"){check(fcntl(99,F_GETFD)<0 && errno==EBADF);return 0;}
            if(m=="closed-stdin"){char c;check(read(0,&c,1)==0);return 0;}
            usleep(2000000);return 0;
        }
        check(argc==2);std::string m=argv[1];
        char path[4096];check(realpath(argv[0],path)!=nullptr);
        OwnedDirectory logical;OwnedLink extra,alias;
        if(m=="hardlink-capture" || m=="link-count-changed"){extra.path=std::string(path)+".link";check(link(path,extra.path.c_str())==0);}
        struct stat expected;check(stat(path,&expected)==0);
        NativeChildCommand command{path,{path,"child",m},{"PATH=/usr/bin:/bin","LC_ALL=C"},getuid(),getgid(),child_image_identity(expected)};
        if(m.find("alias-")==0){
            alias.path=std::string(path)+".alias";std::string text=path;
            if(m=="alias-relative-argv0"){logical.path=std::string(path)+".logical";check(mkdir(logical.path.c_str(),0700)==0);alias.path=logical.path+"/mgrctl";text="../"+std::string(path).substr(std::string(path).rfind('/')+1);}
            check(symlink(text.c_str(),alias.path.c_str())==0);command.path=alias.path;command.argv[0]=alias.path;
            ProgramImage reference(alias.path,getuid(),getgid());command.program_binding=reference.binding();
            if(m=="alias-argv0" || m=="alias-relative-argv0")command.argv.push_back(alias.path);
            if(m=="alias-binding-replaced")check(unlink(alias.path.c_str())==0 && symlink(path,alias.path.c_str())==0);
            if(m=="alias-missing-binding")command.program_binding.clear();
        }
        if(m=="exec-failure"){command.path="/nonexistent/qa";command.argv[0]=command.path;}
        if(m=="invalid-image") {
            command.path=std::string(path)+".invalid";command.argv[0]=command.path;
            int fd=open(command.path.c_str(),O_CREAT|O_EXCL|O_WRONLY,0700);check(fd>=0);check(write(fd,"invalid executable\n",19)==19);close(fd);
            check(stat(command.path.c_str(),&expected)==0);command.expected_identity=child_image_identity(expected);
        }
        if(m=="fd-closed"){int fd=open("/dev/null",O_RDONLY);check(fd>=0 && dup2(fd,99)==99);close(fd);}
        if(m=="inherited-limits"){struct rlimit r={2,2};check(setrlimit(RLIMIT_CPU,&r)==0);}
        bool called=false;
        try {
            NativeOwnedChild child(command,std::chrono::steady_clock::now()+std::chrono::milliseconds(200),37,19);
            if(m=="alias-replaced" || m=="link-count-changed") {
                if(m=="alias-replaced"){check(unlink(alias.path.c_str())==0 && symlink(path,alias.path.c_str())==0);}
                else check(unlink(extra.path.c_str())==0);
                bool refused=false;try{child.run();}catch(const ChildError&){refused=true;}check(refused);return 0;
            }
            if(m=="image-replaced") {
                // Invalidate the pinned image's metadata without substituting another executable.
                struct stat s;check(stat(path,&s)==0);check(chmod(path,0700)==0);
                bool refused=false;try{child.run();}catch(const ChildError&){refused=true;}
                check(refused);check(chmod(path,s.st_mode&0777)==0);return 0;
            }
            auto r=child.run([&]{called=true;if(m=="callback-failure")throw std::runtime_error("callback");});
            check(r.reaped && r.pid>1 && r.exit_known);
            int status;check(waitpid(r.pid,&status,WNOHANG)==-1 && errno==ECHILD);
            if(m=="invalid-image")check(r.failure=="child-exec" && !r.exec_confirmed && (r.exitcode==126 || (r.signal_sent && r.exitcode==-SIGKILL)));
            else if(m=="alias-argv0" || m=="alias-relative-argv0")check(r.stdout_bytes=="alias-ok" && r.exitcode==0 && r.failure.empty());
            else if(m=="capture" || m=="hardlink-capture" || m=="alias-capture")check(r.stdout_bytes=="hello" && r.stderr_bytes=="error" && r.exitcode==0 && r.failure.empty() && r.stdout_eof && r.stderr_eof);
            else if(m=="closed-stdin" || m=="fd-closed" || m=="inherited-limits")check(r.failure.empty() && r.exitcode==0);
            else if(m=="nonzero"){if(r.failure!="child-exit" || r.exitcode!=7)std::cerr<<"failure="<<r.failure<<" exit="<<r.exitcode<<" exec="<<r.exec_confirmed<<" reaped="<<r.reaped<<" eof="<<r.stdout_eof<<r.stderr_eof<<"\n";check(r.failure=="child-exit" && r.exitcode==7);}
            else if(m=="overflow")check(r.failure=="stdout-limit" && r.stdout_bytes==std::string(37,'z'));
            else if(m=="stderr-overflow")check(r.failure=="stderr-limit" && r.stderr_bytes==std::string(19,'z'));
            else if(m=="timeout")check(r.failure=="deadline" && r.signal_sent && r.exitcode==-SIGKILL);
            else if(m=="callback-failure")check(called && r.failure=="controller-callback" && r.signal_sent);
            else if(m=="descendant-pipe"){check(r.failure=="deadline" && r.exitcode==0 && !r.signal_sent && !r.stdout_eof);usleep(800000);}
            else if(m=="one-shot"){bool refused=false;try{child.run();}catch(const ChildError&){refused=true;}check(refused);}
            else check(false);
        } catch(const ChildError&e){if(m=="alias-binding-replaced" || m=="alias-missing-binding"){check(std::string(e.what())=="child-program-binding");return 0;}if(m!="exec-failure")throw;}
        return 0;
    }catch(const std::exception& e){std::cerr<<e.what()<<'\n';return 1;}
}
