// Single-threaded native launcher. No shell, inherited environment, process-group
// signals or manager retirement authority. Linux fexecve uses the pinned image.
#pragma once
#include "program_image.hpp"
#include <algorithm>
#include <chrono>
#include <cerrno>
#include <climits>
#include <csignal>
#include <fcntl.h>
#include <dirent.h>
#include <functional>
#include <poll.h>
#include <set>
#include <sstream>
#include <stdexcept>
#include <string>
#include <sys/resource.h>
#include <sys/stat.h>
#include <sys/socket.h>
#include <sys/wait.h>
#include <unistd.h>
#include <vector>
namespace paymenter_qa {
class ChildError:public std::runtime_error {public:explicit ChildError(const char* s):std::runtime_error(s){}};
inline void child_guard(bool v,const char* s){if(!v)throw ChildError(s);}
struct NativeChildCommand {
    std::string path;std::vector<std::string> argv,environment;uid_t uid;gid_t gid;std::string expected_identity,program_binding;
    NativeChildCommand(std::string p,std::vector<std::string> a,std::vector<std::string> e,uid_t u,gid_t g,std::string i,std::string binding=""):
        path(std::move(p)),argv(std::move(a)),environment(std::move(e)),uid(u),gid(g),expected_identity(std::move(i)),program_binding(std::move(binding)){}
};
struct NativeChildResult {
    pid_t pid=-1,parent=-1,group=-1,session=-1;
    std::string start,boot,stdout_bytes,stderr_bytes,failure;
    int exitcode=0;bool exit_known=false,reaped=false,signal_sent=false,stdout_eof=false,stderr_eof=false,exec_confirmed=false,linux_identity=false;
};
class ChildFd {
public:int value;
    explicit ChildFd(int fd=-1):value(fd){}
    ~ChildFd(){if(value>=0)close(value);}
    ChildFd(const ChildFd&)=delete;ChildFd& operator=(const ChildFd&)=delete;
    void reset(int fd=-1){if(value>=0)close(value);value=fd;}
};
inline bool child_same_image(const struct stat& a,const struct stat& b) {
#ifdef __APPLE__
    bool times=a.st_mtimespec.tv_sec==b.st_mtimespec.tv_sec && a.st_mtimespec.tv_nsec==b.st_mtimespec.tv_nsec &&
        a.st_ctimespec.tv_sec==b.st_ctimespec.tv_sec && a.st_ctimespec.tv_nsec==b.st_ctimespec.tv_nsec;
#else
    bool times=a.st_mtim.tv_sec==b.st_mtim.tv_sec && a.st_mtim.tv_nsec==b.st_mtim.tv_nsec &&
        a.st_ctim.tv_sec==b.st_ctim.tv_sec && a.st_ctim.tv_nsec==b.st_ctim.tv_nsec;
#endif
    return times && a.st_dev==b.st_dev && a.st_ino==b.st_ino && a.st_mode==b.st_mode && a.st_uid==b.st_uid &&
        a.st_gid==b.st_gid && a.st_nlink==b.st_nlink && a.st_size==b.st_size;
}
inline std::string child_image_identity(const struct stat& s) {return program_file_identity(s);}

#ifdef __linux__
inline std::string child_proc_file(const std::string& path,size_t bound) {
    ChildFd fd(open(path.c_str(),O_RDONLY|O_CLOEXEC|O_NOFOLLOW));child_guard(fd.value>=0,"child-kernel-file");
    std::string raw;char b[1024];ssize_t n;
    while((n=read(fd.value,b,sizeof(b)))>0){raw.append(b,n);child_guard(raw.size()<=bound,"child-kernel-bound");}
    child_guard(n==0,"child-kernel-file");return raw;
}
inline std::string child_start(pid_t pid,pid_t parent) {
    auto raw=child_proc_file("/proc/"+std::to_string(pid)+"/stat",8192);auto end=raw.rfind(')');
    child_guard(end!=std::string::npos && raw.substr(0,raw.find(' '))==std::to_string(pid),"child-kernel-identity");
    std::istringstream f(raw.substr(end+1));std::string state,ppid,group,session,value;
    child_guard(bool(f>>state>>ppid>>group>>session) && ppid==std::to_string(parent) && group==std::to_string(pid) && session==group,"child-kernel-identity");
    for(int i=0;i<16;++i)child_guard(bool(f>>value),"child-kernel-identity");
    child_guard(!value.empty() && value.find_first_not_of("0123456789")==std::string::npos,"child-kernel-identity");return value;
}
#endif
inline std::vector<int> child_open_descriptors() {
#ifdef __linux__
    DIR* tasks=opendir("/proc/self/task");child_guard(tasks!=nullptr,"child-thread-inventory");
    size_t threads=0;while(auto entry=readdir(tasks)){if(entry->d_name[0]!='.')++threads;}
    closedir(tasks);child_guard(threads==1,"child-single-thread-required");
    DIR* listing=opendir("/proc/self/fd");
#else
    DIR* listing=opendir("/dev/fd");
#endif
    child_guard(listing!=nullptr,"child-fd-inventory");std::vector<int> fds;bool valid=true;
    errno=0;while(auto entry=readdir(listing)) {
        std::string value=entry->d_name;if(value=="." || value=="..")continue;
        if(value.empty() || value.size()>10 || value.find_first_not_of("0123456789")!=std::string::npos){valid=false;break;}
        auto fd=std::stoull(value);if(fd>INT_MAX || fds.size()>=4096){valid=false;break;}fds.push_back(static_cast<int>(fd));
    }
    int error=errno;closedir(listing);child_guard(valid && !error,"child-fd-inventory");return fds;
}
class NativeOwnedChild {
    using Mono=std::chrono::steady_clock;
    const NativeChildCommand command_;const Mono::time_point end_;const size_t out_limit_,err_limit_;
    const pid_t owner_=getpid();std::unique_ptr<ProgramImage> program_;ChildFd image_;struct stat stamp_;bool consumed_=false;
    static void pipe_pair(ChildFd& r,ChildFd& w) {
        int p[2];child_guard(pipe(p)==0,"child-pipe");r.reset(p[0]);w.reset(p[1]);
        child_guard(fcntl(r.value,F_SETFD,FD_CLOEXEC)==0 && fcntl(w.value,F_SETFD,FD_CLOEXEC)==0,"child-pipe");
    }
    void unchanged()const {
        try{program_->unchanged();}catch(const ProgramImageError&){throw ChildError("child-image-changed");}
        struct stat a;child_guard(getpid()==owner_ && fstat(image_.value,&a)==0 && child_same_image(stamp_,a),"child-image-changed");
    }
    static bool limit(int kind,rlim_t value){
        struct rlimit inherited;if(getrlimit(kind,&inherited)!=0)return false;
        value=std::min(value,inherited.rlim_max);struct rlimit r={value,value};return setrlimit(kind,&r)==0;
    }
    static void drain(ChildFd& fd,std::string& bytes,size_t bound,bool& eof,std::string& failure,const char* reason) {
        if(fd.value<0)return;
        char raw[4096];
        for(unsigned i=0;i<16;++i) {
            ssize_t n=read(fd.value,raw,sizeof(raw));
            if(n>0) {
                auto used=static_cast<size_t>(n),keep=std::min(used,bound-bytes.size());bytes.append(raw,keep);
                if(keep<used && failure.empty())failure=reason;
            } else if(n==0){eof=true;fd.reset();return;}
            else if(errno==EINTR)continue;
            else if(errno==EAGAIN || errno==EWOULDBLOCK)return;
            else {if(failure.empty())failure="pipe-read";fd.reset();return;}
        }
    }
public:
    NativeOwnedChild(NativeChildCommand command,Mono::time_point end,size_t out_limit=16384,size_t err_limit=8192):
        command_(std::move(command)),end_(end),out_limit_(out_limit),err_limit_(err_limit) {
        child_guard(!command_.path.empty() && command_.path[0]=='/' && !command_.argv.empty() && command_.argv[0]==command_.path &&
            command_.argv.size()<=32 && command_.environment.size()<=16 && out_limit>0 && out_limit<=32768 && err_limit>0 && err_limit<=8192 &&
            end>Mono::now() && end<=Mono::now()+std::chrono::seconds(30),"child-command");
        size_t size=0;std::set<std::string> keys;
        for(const auto& values:{command_.argv,command_.environment})for(const auto& value:values) {
            child_guard(!value.empty() && value.size()<=4096 && value.find('\0')==std::string::npos,"child-command");size+=value.size()+1;
        }
        for(const auto& value:command_.environment){auto at=value.find('=');child_guard(at>0 && at!=std::string::npos && keys.insert(value.substr(0,at)).second,"child-environment");}
        child_guard(size<=16384 && command_.path.find('\0')==std::string::npos,"child-command");
        try{program_.reset(new ProgramImage(command_.path,command_.uid,command_.gid));}catch(const ProgramImageError&){throw ChildError("child-image");}
        child_guard(command_.program_binding.empty()?!program_->alias():command_.program_binding==program_->binding(),"child-program-binding");
        image_.reset(fcntl(program_->descriptor(),F_DUPFD_CLOEXEC,3));
        child_guard(image_.value>=0 && fstat(image_.value,&stamp_)==0 && S_ISREG(stamp_.st_mode) && stamp_.st_nlink>0 &&
            stamp_.st_uid==command_.uid && stamp_.st_gid==command_.gid && !(stamp_.st_mode&0022) && (stamp_.st_mode&0111) &&
            stamp_.st_size>0 && stamp_.st_size<=16777216 && child_image_identity(stamp_)==command_.expected_identity,"child-image");unchanged();
    }
    NativeChildResult run(std::function<void()> callback=[] {}) {
        child_guard(!consumed_ && getpid()==owner_,"child-reused");consumed_=true;unchanged();
        child_guard(Mono::now()<end_,"child-deadline");
        for(int i=0;i<3;++i)child_guard(fcntl(i,F_GETFD)>=0,"child-standard-fds");
        ChildFd out,ow,err,ew,ready,rw,gate,gw,exec_error,xw;
        pipe_pair(out,ow);pipe_pair(err,ew);pipe_pair(ready,rw);{int p[2];child_guard(socketpair(AF_UNIX,SOCK_STREAM,0,p)==0,"child-gate");gate.reset(p[0]);gw.reset(p[1]);
            child_guard(fcntl(p[0],F_SETFD,FD_CLOEXEC)==0 && fcntl(p[1],F_SETFD,FD_CLOEXEC)==0,"child-gate");
#ifdef __APPLE__
            int no_pipe=1;child_guard(setsockopt(gw.value,SOL_SOCKET,SO_NOSIGPIPE,&no_pipe,sizeof(no_pipe))==0,"child-gate");
#endif
        }pipe_pair(exec_error,xw);
        ChildFd null(open("/dev/null",O_RDONLY|O_CLOEXEC));child_guard(null.value>=0,"child-stdin");
        std::vector<char*> args,env;
        for(const auto& s:command_.argv){args.push_back(const_cast<char*>(s.c_str()));}
        args.push_back(nullptr);
        for(const auto& s:command_.environment){env.push_back(const_cast<char*>(s.c_str()));}
        env.push_back(nullptr);
        const auto descriptors=child_open_descriptors();
        auto child=fork();child_guard(child>=0,"child-fork");
        if(child==0) {
            // Async-signal-safe calls only after fork. No callbacks, allocation,
            // exceptions, OpenSSL, shell, arbitrary inherited descriptors or input.
            bool ok=setsid()>0 && dup2(null.value,0)>=0 && dup2(ow.value,1)>=0 && dup2(ew.value,2)>=0;
            ok=ok && limit(RLIMIT_CORE,0) && limit(RLIMIT_CPU,31) && limit(RLIMIT_FSIZE,16777216) && limit(RLIMIT_NOFILE,64);
#ifdef __linux__
            ok=ok && limit(RLIMIT_AS,2147483648ULL);
#endif
            for(int fd:descriptors)if(fd>=3 && fd!=image_.value && fd!=rw.value && fd!=gate.value && fd!=xw.value)close(fd);
            char byte='R';if(!ok || write(rw.value,&byte,1)!=1)_exit(125);
            close(rw.value);if(read(gate.value,&byte,1)!=1 || byte!='G')_exit(125);close(gate.value);
#ifdef __linux__
            fexecve(image_.value,args.data(),env.data());
#else
            execve(command_.path.c_str(),args.data(),env.data());
#endif
            byte='E';(void)write(xw.value,&byte,1);_exit(126);
        }
        NativeChildResult result;result.pid=child;result.parent=owner_;
        ow.reset();ew.reset();rw.reset();gate.reset();xw.reset();null.reset();
        for(auto fd:{out.value,err.value,ready.value,exec_error.value}) {
            if(fcntl(fd,F_SETFL,O_NONBLOCK)!=0)result.failure="pipe-nonblock";
        }
        bool gate_open=false,exec_eof=false,exec_failed=false,identity=false;int status=0;
        auto reap=[&] {
            if(result.reaped)return;
            auto p=waitpid(child,&status,WNOHANG);
            if(p==child) {result.reaped=true;result.exit_known=true;
                result.exitcode=WIFEXITED(status)?WEXITSTATUS(status):WIFSIGNALED(status)?-WTERMSIG(status):INT_MIN;}
            else if(p<0 && errno!=EINTR){if(result.failure.empty())result.failure="child-wait-held";}
        };
        auto retire=[&] {
            reap();if(result.reaped)return;
            bool same=false;
            try {
#ifdef __linux__
                same=identity && child_start(child,owner_)==result.start;
#else
                same=identity && getpgid(child)==child && getsid(child)==child;
#endif
            }catch(...){same=false;}
            if(same && kill(child,SIGKILL)==0)result.signal_sent=true;
            else if(result.failure.empty())result.failure="child-identity-held";
        };
        while(true) {
            reap();
            if(!gate_open && ready.value>=0 && result.failure.empty()) {
                char b;auto n=read(ready.value,&b,1);
                if(n==1) {
                    try {
                        child_guard(b=='R' && getpgid(child)==child && getsid(child)==child,"child-kernel-identity");
                        result.group=child;result.session=child;
#ifdef __linux__
                        result.start=child_start(child,owner_);result.boot=child_proc_file("/proc/sys/kernel/random/boot_id",128);
                        if(!result.boot.empty() && result.boot.back()=='\n'){result.boot.pop_back();}
                        result.linux_identity=true;
#else
                        result.start="unqualified";result.boot="unqualified";
#endif
                        identity=true;unchanged();char go='G';
                        #ifdef __linux__
                        child_guard(send(gw.value,&go,1,MSG_NOSIGNAL)==1,"child-gate");
#else
                        child_guard(send(gw.value,&go,1,0)==1,"child-gate");
#endif
                        gate_open=true;gw.reset();ready.reset();
                    }catch(...){result.failure="child-identity-held";}
                }else if(n==0){ready.reset();if(result.failure.empty())result.failure="child-startup";}
                else if(errno!=EAGAIN && errno!=EWOULDBLOCK && errno!=EINTR)result.failure="child-startup";
            }
            drain(out,result.stdout_bytes,out_limit_,result.stdout_eof,result.failure,"stdout-limit");
            drain(err,result.stderr_bytes,err_limit_,result.stderr_eof,result.failure,"stderr-limit");
            if(exec_error.value>=0) {
                char b;auto n=read(exec_error.value,&b,1);
                if(n>0){exec_failed=true;if(result.failure.empty())result.failure="child-exec";}
                else if(n==0){exec_eof=true;exec_error.reset();}
            }
            result.exec_confirmed=gate_open && exec_eof && !exec_failed;
            if(result.reaped && result.stdout_eof && result.stderr_eof && exec_eof)break;
            if(Mono::now()>=end_ && result.failure.empty())result.failure="deadline";
            if(!result.failure.empty()) {retire();break;}
            if(gate_open && !result.reaped)try{callback();}catch(...){result.failure="controller-callback";retire();break;}
            if(Mono::now()>=end_)continue;
            struct pollfd fds[4]={{out.value,POLLIN,0},{err.value,POLLIN,0},{ready.value,POLLIN,0},{exec_error.value,POLLIN,0}};
            auto left=std::chrono::duration_cast<std::chrono::milliseconds>(end_-Mono::now()).count();
            if(poll(fds,4,static_cast<int>(std::max<long long>(0,std::min<long long>(10,left))))<0 && errno!=EINTR){result.failure="child-poll";retire();break;}
        }
        gw.reset();ready.reset();
        // A killed direct child should promptly reap. A kernel-stuck/unknown child
        // stays held; there is no unbounded wait or signal to a group/descendant.
        auto reap_end=Mono::now()+std::chrono::milliseconds(200);
        while(!result.reaped && Mono::now()<reap_end){reap();if(!result.reaped)poll(nullptr,0,1);}
        drain(out,result.stdout_bytes,out_limit_,result.stdout_eof,result.failure,"stdout-limit");
        drain(err,result.stderr_bytes,err_limit_,result.stderr_eof,result.failure,"stderr-limit");
        if(result.failure.empty() && (!result.reaped || !result.exec_confirmed))result.failure="child-incomplete";
        if(result.failure.empty() && result.exitcode!=0)result.failure="child-exit";
        return result;
    }
};
}
