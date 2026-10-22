#ifndef PAYMENTER_QA_AUTHORITY_BRIDGE_IMPLEMENTATION
#define PAYMENTER_QA_AUTHORITY_BRIDGE_IMPLEMENTATION
#include "authority_bridge.hpp"
#include "program_image.hpp"
#include <openssl/sha.h>
#include <algorithm>
#include <cerrno>
#include <climits>
#include <cstdint>
#include <ctime>
#include <functional>
#include <map>
#include <mutex>
#include <sstream>
#include <thread>
#include <vector>
#include <fcntl.h>
#include <sys/stat.h>
#include <unistd.h>
#ifdef __linux__
#include <dlfcn.h>
#include <sys/sysmacros.h>
#include <sys/vfs.h>
#endif

namespace paymenter_qa {
namespace {
using Clock=std::chrono::steady_clock;
void guard(bool ok, const char* code) { if (!ok) throw ProbeError(code); }
struct Fd {
    int fd;
    explicit Fd(int value):fd(value) { guard(fd>=0,"qa-private-open"); }
    ~Fd() { close(fd); }
    Fd(const Fd&)=delete; Fd& operator=(const Fd&)=delete;
};
struct Owner { uid_t uid; gid_t gid; };
struct Stamp {
    dev_t dev; ino_t ino; off_t size; mode_t mode; uid_t uid; gid_t gid;
    time_t mtime,ctime; long mnano,cnano;
    bool operator==(const Stamp& b) const {
        return dev==b.dev && ino==b.ino && size==b.size && mode==b.mode && uid==b.uid &&
            gid==b.gid && mtime==b.mtime && ctime==b.ctime && mnano==b.mnano && cnano==b.cnano;
    }
};
Stamp stamp(const struct stat& s) {
#ifdef __APPLE__
    long m=s.st_mtimespec.tv_nsec,c=s.st_ctimespec.tv_nsec;
#else
    long m=s.st_mtim.tv_nsec,c=s.st_ctim.tv_nsec;
#endif
    return {s.st_dev,s.st_ino,s.st_size,s.st_mode,s.st_uid,s.st_gid,s.st_mtime,s.st_ctime,m,c};
}
std::string hash(const std::string& raw) {
    unsigned char result[SHA256_DIGEST_LENGTH];
    guard(SHA256(reinterpret_cast<const unsigned char*>(raw.data()),raw.size(),result)!=nullptr,"qa-private-hash");
    const char* hex="0123456789abcdef"; std::string out;
    for (auto c:result) { out+=hex[c>>4]; out+=hex[c&15]; }
    return out;
}
bool hex64(const std::string& s) {
    return s.size()==64 && s.find_first_not_of("0123456789abcdef")==std::string::npos;
}
uint64_t number(const std::string& s) {
    guard(!s.empty() && s.size()<=20 && s[0]>='1' && s[0]<='9',"qa-private-number");
    uint64_t value=0;
    for (char c:s) {
        guard(c>='0' && c<='9' && value<=(UINT64_MAX-static_cast<unsigned>(c-'0'))/10,"qa-private-number");
        value=value*10+static_cast<unsigned>(c-'0');
    }
    return value;
}
void canonical(const std::string& path) {
    guard(path.size()>1 && path.size()<=4096 && path[0]=='/' && path.back()!='/' &&
        path.find("//")==std::string::npos && path.find('\\')==std::string::npos,"qa-private-path");
    std::istringstream parts(path.substr(1)); std::string part;
    while (std::getline(parts,part,'/')) guard(!part.empty() && part!="." && part!="..","qa-private-path");
    for (unsigned char c:path) guard(c>32 && c<127,"qa-private-path");
}
int directory_fd(const std::string& path,Owner owner,bool private_leaf) {
    canonical(path);
    Fd current(open("/",O_RDONLY|O_DIRECTORY|O_CLOEXEC));
    int next=dup(current.fd); guard(next>=0,"qa-private-open");
    std::istringstream parts(path.substr(1)); std::string part;
    try {
        while (std::getline(parts,part,'/')) {
            Fd parent(next); next=-1;
            next=openat(parent.fd,part.c_str(),O_RDONLY|O_DIRECTORY|O_NOFOLLOW|O_CLOEXEC);
            guard(next>=0,"qa-private-directory");
            struct stat s; guard(fstat(next,&s)==0 && S_ISDIR(s.st_mode) &&
                (s.st_uid==0 || s.st_uid==owner.uid) && !(s.st_mode&0022),"qa-private-directory");
        }
        struct stat s; guard(fstat(next,&s)==0,"qa-private-directory");
        if (private_leaf) guard(s.st_uid==owner.uid && s.st_gid==owner.gid &&
            (s.st_mode&07777)==0700,"qa-private-directory");
        return next;
    } catch (...) { if (next>=0) close(next); throw; }
}
struct File {
    std::string raw;Stamp identity;std::string program_binding;
    File():identity{}{}
    File(std::string data,Stamp id,std::string program=""):raw(std::move(data)),identity(id),program_binding(std::move(program)){}
};
void custody(const struct stat& s,Owner owner) {
    guard(S_ISREG(s.st_mode) && s.st_uid==owner.uid && s.st_gid==owner.gid &&
          (s.st_mode&07777)==0600 && s.st_nlink==1,"qa-private-file");
}
std::string bounded(int fd,size_t limit) {
    std::string raw; char buffer[8192];
    while (true) {
        ssize_t count=read(fd,buffer,std::min(sizeof buffer,limit+1-raw.size()));
        guard(count>=0,"qa-private-read");
        if (!count) break;
        raw.append(buffer,static_cast<size_t>(count));
        guard(raw.size()<=limit,"qa-private-size");
    }
    return raw;
}
class PrivateDirectory {
    const std::string path_; const Owner owner_; Fd fd_;
    const dev_t dev_; const ino_t ino_;
    static struct stat status(int fd) { struct stat s; guard(fstat(fd,&s)==0,"qa-private-directory"); return s; }
    static void name(const std::string& n) {
        guard(!n.empty() && n.size()<64 && n!="." && n!=".." &&
              n.find_first_not_of("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-")==std::string::npos,"qa-private-name");
    }
public:
    PrivateDirectory(const std::string& path,Owner owner):path_(path),owner_(owner),
        fd_(directory_fd(path,owner,true)),dev_(status(fd_.fd).st_dev),ino_(status(fd_.fd).st_ino) {}
    int descriptor() const { return fd_.fd; }
    const std::string& path() const { unchanged(); return path_; }
    void unchanged() const {
        Fd fresh(directory_fd(path_,owner_,true)); auto s=status(fresh.fd);
        guard(s.st_dev==dev_ && s.st_ino==ino_,"qa-private-directory-changed");
    }
    bool exists(const std::string& n) const {
        name(n); unchanged(); struct stat s;
        if (fstatat(fd_.fd,n.c_str(),&s,AT_SYMLINK_NOFOLLOW)==0) return true;
        guard(errno==ENOENT,"qa-private-stat"); return false;
    }
private:
    File read_at(const std::string& n,size_t limit) const {
        name(n);Fd file(openat(fd_.fd,n.c_str(),O_RDONLY|O_NOFOLLOW|O_CLOEXEC|O_NONBLOCK));
        struct stat before,after;guard(fstat(file.fd,&before)==0,"qa-private-stat");custody(before,owner_);
        guard(before.st_size>0 && static_cast<uint64_t>(before.st_size)<=limit,"qa-private-size");
        auto raw=bounded(file.fd,limit);
        guard(fstat(file.fd,&after)==0 && stamp(before)==stamp(after) && raw.size()==static_cast<uint64_t>(before.st_size),"qa-private-file-changed");
        custody(after,owner_);return {raw,stamp(after)};
    }
public:
    File read(const std::string& n,size_t limit) const {
        unchanged();auto file=read_at(n,limit);unchanged();return file;
    }
    // Every file is still opened relative to the original held descriptor with
    // NOFOLLOW, full custody, bounded bytes and unchanged nanosecond metadata.
    // Revalidate path/ancestor custody around the complete bounded batch rather
    // than walking the same directory ancestry twice for each immutable original.
    std::vector<File> read_batch(const std::vector<std::pair<std::string,size_t>>& names) const {
        guard(!names.empty() && names.size()<=128,"qa-private-batch-bound");unchanged();std::vector<File> files;
        for(const auto& item:names){guard(item.second>0 && item.second<=65536,"qa-private-batch-bound");files.push_back(read_at(item.first,item.second));}
        unchanged();return files;
    }
    void create(const std::string& n,const std::string& raw) const {publish(n,raw,4096);}
    // Original process streams retain bounded framing even when the stream is empty.
    void create_caller_reply(const std::string& name,const std::string& raw) const {
        guard(name=="caller.F1.stdout" || name=="caller.F4.stdout","qa-private-caller-name");publish(name,raw,16384);
    }
    void create_capture(const std::string& name,const std::string& raw) const {
        guard(name.find("capture.")==0 && name.size()>8,"qa-private-capture-name");publish(name,raw,32768);
    }
    // Fixed standalone observation only; ordinary controls keep their 4 KiB bound.
    void create_manual_financial_observation(const std::string& raw) const {
        publish("manual.read.original",raw,65536);
    }
    // The retirement archive has a separate fixed-name 64 KiB publication ceiling.
    void create_retirement_archive(const std::string& raw) const {publish("qa.retirement.original",raw,65536);}
private:
    void publish(const std::string& n,const std::string& raw,size_t maximum) const {
        name(n); unchanged(); guard(!raw.empty() && raw.size()<=maximum,"qa-private-size");
        Fd file(openat(fd_.fd,n.c_str(),O_WRONLY|O_CREAT|O_EXCL|O_NOFOLLOW|O_CLOEXEC,0600));
        struct stat s; guard(fstat(file.fd,&s)==0,"qa-private-stat"); custody(s,owner_);
        size_t used=0;
        while (used<raw.size()) {
            ssize_t count=write(file.fd,raw.data()+used,raw.size()-used);
            guard(count>0,"qa-private-write"); used+=static_cast<size_t>(count);
        }
        guard(fsync(file.fd)==0 && fsync(fd_.fd)==0,"qa-private-sync"); unchanged();
        // Failed/partial originals remain; no overwrite or cleanup is attempted.
    }
};
using Fields=std::map<std::string,std::string>;
Fields parse(const std::string& raw,const std::string& purpose,const std::string& names) {
    guard(!raw.empty() && raw.size()<=4096 && raw.back()=='\n',"qa-private-format");
    for (unsigned char c:raw) guard(c=='\n' || (c>=32 && c<127),"qa-private-format");
    std::istringstream in(raw),order(names); std::string line,name; Fields out;
    guard(bool(std::getline(in,line)) && line==purpose,"qa-private-purpose");
    while (order>>name) {
        guard(bool(std::getline(in,line)) && line.compare(0,name.size()+1,name+"=")==0,"qa-private-fields");
        auto value=line.substr(name.size()+1);
        guard(!value.empty() && value.size()<=1024 && value.find('=')==std::string::npos,"qa-private-value");
        guard(out.emplace(name,value).second,"qa-private-fields");
    }
    guard(!std::getline(in,line),"qa-private-fields"); return out;
}
std::string actor(const std::string& encoded) {
    guard(!encoded.empty() && encoded.size()<=256 && encoded.size()%2==0 &&
          encoded.find_first_not_of("0123456789abcdef")==std::string::npos,"qa-private-actor");
    std::string out;
    for (size_t i=0;i<encoded.size();i+=2) {
        unsigned value=static_cast<unsigned>(std::stoul(encoded.substr(i,2),nullptr,16));
        guard(value>=32 && value!=127,"qa-private-actor"); out+=static_cast<char>(value);
    }
    return out;
}
struct Lease { Fields fields; std::string actor; uint64_t issued,expires; };
Lease lease(const File& file) {
    auto f=parse(file.raw,"paymenter-source-qa-authority-v1",
        "intent run boot actor_hex issued expires module_path module_sha256 runtime_path runtime_sha256 registry_sha256 build_sha256 "
        "nonce_F1 facts_F1 nonce_F2 facts_F2 nonce_F3 facts_F3 nonce_F4 facts_F4");
    guard(f.at("intent")=="framework-qa-execute" && hex64(f.at("run")),"qa-private-intent");
    guard(f.at("boot").size()==36 && f.at("boot").find_first_not_of("0123456789abcdef-")==std::string::npos,"qa-private-boot");
    for (const auto& n:{"module_sha256","runtime_sha256","registry_sha256","build_sha256"}) guard(hex64(f.at(n)),"qa-private-digest");
    std::vector<std::string> nonces;
    for (const auto& id:{"F1","F2","F3","F4"}) {
        guard(hex64(f.at(std::string("nonce_")+id)) && hex64(f.at(std::string("facts_")+id)),"qa-private-digest");
        nonces.push_back(f.at(std::string("nonce_")+id));
    }
    std::sort(nonces.begin(),nonces.end()); guard(std::adjacent_find(nonces.begin(),nonces.end())==nonces.end(),"qa-private-reused-nonce");
    canonical(f.at("module_path")); canonical(f.at("runtime_path"));
    auto issued=number(f.at("issued")),expires=number(f.at("expires"));
    guard(issued<expires && expires-issued<=600 && expires<=INT64_MAX,"qa-private-expiry");
    return {f,actor(f.at("actor_hex")),issued,expires};
}
inline uint64_t now_seconds() { auto now=time(nullptr); guard(now>0,"qa-private-clock"); return static_cast<uint64_t>(now); }
Mode case_mode(const std::string& c) {
    if (c=="F1") return Mode::Normal;
    if (c=="F2") return Mode::ThrowAfterBusiness;
    if (c=="F3") return Mode::ThrowAfterJournal;
    if (c=="F4") return Mode::ObserveBeforeReturn;
    throw ProbeError("qa-private-case");
}
bool same_file(const File& a,const File& b) { return a.identity==b.identity && a.raw==b.raw && a.program_binding==b.program_binding; }
class ProtectedAuthority: public FrameworkAuthority {
    struct Intent {
        File original; Grant grant; uint64_t issued,expires;
        const void* session; std::thread::id thread;
    };
    std::shared_ptr<PrivateDirectory> dir_;
    const File authority_,registry_,build_; const Lease lease_;
    const pid_t process_; const std::string start_;
    const std::function<bool()> runtime_; const std::function<uint64_t()> wall_;
    const Clock::time_point deadline_;
    mutable std::mutex mutex_; mutable std::map<std::string,Intent> intents_;
    mutable bool held_=false;
    mutable std::map<std::string,File> checkpoints_;
    void process() const { guard(getpid()==process_,"qa-private-process"); }
    static File original(const std::shared_ptr<PrivateDirectory>& dir,const std::string& name,
                         const File& authority,const std::string& digest_name) {
        auto l=lease(authority); auto f=dir->read(name,65536);
        guard(hash(f.raw)==l.fields.at(digest_name),"qa-private-original"); return f;
    }
    void base() const {
        guard(!held_ && getpid()==process_ && runtime_(),"qa-private-runtime");
        auto now=wall_();
        guard(now>=lease_.issued && now<lease_.expires && Clock::now()<deadline_,"qa-private-expiry");
        guard(!dir_->exists("revoked.v1") && !dir_->exists("retired.v1"),"qa-private-revoked");
        guard(same_file(authority_,dir_->read("authority.v1",4096)) &&
              same_file(registry_,dir_->read("registry.original",65536)) &&
              same_file(build_,dir_->read("build.original",65536)),"qa-private-original-changed");
        guard(Clock::now()<deadline_ && wall_()<lease_.expires,"qa-private-expiry");
    }
    bool current_locked(const Grant& g) const {
        base(); auto found=intents_.find(g.case_id);
        if (found==intents_.end()) return false;
        const auto& expected=found->second.grant;
        if (g.run_id!=expected.run_id || g.case_id!=expected.case_id || g.nonce!=expected.nonce ||
            g.actor!=expected.actor || g.facts_hash!=expected.facts_hash || g.mode!=expected.mode ||
            g.expires!=expected.expires || Clock::now()>=g.expires ||
            found->second.thread!=std::this_thread::get_id()) return false;
        guard(same_file(found->second.original,dir_->read("intent.v1",4096)),"qa-private-intent-changed");
        base(); auto now=wall_();
        return Clock::now()<g.expires && now>=found->second.issued && now<found->second.expires;
    }
public:
    // Internal only. Native factory supplies kernel/loaded-image verification;
    // local tests supply authored runtime facts and their own fixture UID/GID.
    ProtectedAuthority(std::shared_ptr<PrivateDirectory> dir,const File& authority,
                       const std::string& start,std::function<bool()> runtime,
                       std::function<uint64_t()> wall=now_seconds):dir_(dir),authority_(authority),
        registry_(original(dir,"registry.original",authority,"registry_sha256")),
        build_(original(dir,"build.original",authority,"build_sha256")),lease_(lease(authority)),
        process_(getpid()),start_(start),runtime_(runtime),wall_(wall),deadline_(Clock::now()+
            std::chrono::seconds(lease_.expires>wall_()?std::min<uint64_t>(600,lease_.expires-wall_()):0)) {
        number(start_); base();
        for (const auto& id:{"F1","F2","F3","F4"})
            guard(!dir_->exists(std::string("writer.")+id+".original"),"qa-private-reused-operation");
    }
    std::string operation_id() const override {
        process();
        std::lock_guard<std::mutex> lock(mutex_); base(); return lease_.fields.at("run");
    }
    Grant authorize(const Request& r) const override {
        process();
        std::lock_guard<std::mutex> lock(mutex_); base(); auto mode=case_mode(r.case_id);
        guard(r.identity && r.action=="paymenter.qa.transaction" && r.actor==lease_.actor &&
              r.run_id==lease_.fields.at("run") && hex64(r.nonce),"qa-private-request");
        auto raw=dir_->read("intent.v1",4096);
        auto f=parse(raw.raw,"paymenter-source-qa-intent-v1","run case nonce facts pid start issued expires");
        auto issued=number(f.at("issued")),expires=number(f.at("expires")),now=wall_();
        guard(f.at("run")==r.run_id && f.at("case")==r.case_id && f.at("nonce")==r.nonce &&
            f.at("nonce")==lease_.fields.at("nonce_"+r.case_id) &&
            f.at("facts")==lease_.fields.at("facts_"+r.case_id) &&
            number(f.at("pid"))==static_cast<uint64_t>(process_) && f.at("start")==start_,"qa-private-binding");
        guard(issued>=lease_.issued && issued<=now && now<expires && expires<=lease_.expires &&
              expires>issued && expires-issued<=30,"qa-private-case-expiry");
        Grant g{r.run_id,r.case_id,r.nonce,r.actor,f.at("facts"),mode,
                std::min(deadline_,Clock::now()+std::chrono::seconds(expires-now))};
        guard(intents_.emplace(r.case_id,Intent{raw,g,issued,expires,r.identity,std::this_thread::get_id()}).second,"qa-private-reused-case");
        base(); guard(Clock::now()<g.expires,"qa-private-case-expiry"); return g;
    }
    bool current(const Grant& g) const override {
        if (getpid()!=process_) return false;
        try { std::lock_guard<std::mutex> lock(mutex_); return current_locked(g); }
        catch (...) { return false; }
    }
    bool barrier_released(const Grant& g) const override {
        process();
        std::lock_guard<std::mutex> lock(mutex_);
        guard(current_locked(g) && g.case_id=="F4","qa-private-barrier");
        if (!dir_->exists("release.F4.v1")) return false;
        auto f=parse(dir_->read("release.F4.v1",4096).raw,"paymenter-source-qa-release-v1","run case nonce original_sha256");
        guard(f.at("run")==g.run_id && f.at("case")==g.case_id && f.at("nonce")==g.nonce &&
              hex64(f.at("original_sha256")),"qa-private-barrier");
        guard(hash(dir_->read("barrier.original",65536).raw)==f.at("original_sha256"),"qa-private-barrier-original");
        guard(current_locked(g),"qa-private-barrier"); return true;
    }
    void record_refusal(const Request& r) const override {
        process();std::lock_guard<std::mutex> lock(mutex_);base();
        guard(r.identity && r.action=="paymenter.qa.transaction" && r.actor==lease_.actor && r.run_id==lease_.fields.at("run") &&
            r.case_id=="F5" && r.nonce==hash(authority_.raw+"\nF5\n") && !dir_->exists("intent.v1"),"qa-private-refusal");
        auto raw="paymenter-source-qa-refusal-v1\nrun="+r.run_id+"\ncase=F5\nnonce="+r.nonce+
            "\npid="+std::to_string(process_)+"\nstart="+start_+"\nauthority_sha256="+hash(authority_.raw)+"\n";
        try{dir_->create("refusal.F5.original",raw);base();}catch(...){held_=true;throw;}
    }
    void record_checkpoint(const Request& r,const Grant& g,Checkpoint stage) const override {
        process();std::lock_guard<std::mutex> lock(mutex_);
        guard(current_locked(g) && r.identity==intents_.at(g.case_id).session && r.run_id==g.run_id &&
            r.case_id==g.case_id && r.nonce==g.nonce && r.actor==g.actor && r.action=="paymenter.qa.transaction","qa-private-checkpoint");
        const std::string prefix="checkpoint."+g.case_id+".";
        std::string label;
        if(stage==Checkpoint::BusinessReturned)label="business";
        else if(stage==Checkpoint::JournalReturned)label="journal";
        else if(stage==Checkpoint::InjectingBusiness && g.mode==Mode::ThrowAfterBusiness)label="inject-business";
        else if(stage==Checkpoint::InjectingJournal && g.mode==Mode::ThrowAfterJournal)label="inject-journal";
        else if(stage==Checkpoint::BarrierEntered && g.mode==Mode::ObserveBeforeReturn)label="barrier";
        else throw ProbeError("qa-private-checkpoint-stage");
        auto writer=dir_->read("writer."+g.case_id+".original",4096);
        auto w=parse(writer.raw,"paymenter-source-qa-writer-v1","run case nonce facts connection pid start intent_sha256");
        guard(w.at("run")==g.run_id && w.at("case")==g.case_id && w.at("nonce")==g.nonce && w.at("facts")==g.facts_hash &&
            w.at("pid")==std::to_string(process_) && w.at("start")==start_ &&
            w.at("intent_sha256")==hash(intents_.at(g.case_id).original.raw),"qa-private-checkpoint-writer");
        for(const auto& prior:checkpoints_)guard(same_file(prior.second,dir_->read(prior.first,4096)),"qa-private-checkpoint-changed");
        bool business=checkpoints_.count(prefix+"business.original"),journal=checkpoints_.count(prefix+"journal.original");
        guard((stage==Checkpoint::BusinessReturned && !business && !journal) ||
            (stage==Checkpoint::JournalReturned && business && !journal && g.mode!=Mode::ThrowAfterBusiness) ||
            (stage==Checkpoint::InjectingBusiness && business && !journal) ||
            (stage==Checkpoint::InjectingJournal && business && journal) ||
            (stage==Checkpoint::BarrierEntered && business && journal),"qa-private-checkpoint-order");
        auto name=prefix+label+".original";
        auto raw="paymenter-source-qa-checkpoint-v1\nrun="+g.run_id+"\ncase="+g.case_id+"\nnonce="+g.nonce+"\nfacts="+g.facts_hash+
            "\nstage="+label+"\nwriter_sha256="+hash(writer.raw)+"\nintent_sha256="+hash(intents_.at(g.case_id).original.raw)+"\n";
        try {dir_->create(name,raw);checkpoints_.emplace(name,dir_->read(name,4096));guard(current_locked(g),"qa-private-checkpoint");}
        catch(...){held_=true;throw;}
    }
    void record_connection(const Request& r,const Grant& g,const std::string& connection) const override {
        process();
        std::lock_guard<std::mutex> lock(mutex_);
        guard(current_locked(g) && r.identity && r.action=="paymenter.qa.transaction" &&
              r.run_id==g.run_id && r.case_id==g.case_id && r.nonce==g.nonce && r.actor==g.actor &&
              r.identity==intents_.at(g.case_id).session,"qa-private-diagnostic");
        number(connection);
        std::string body="paymenter-source-qa-writer-v1\nrun="+g.run_id+"\ncase="+g.case_id+
            "\nnonce="+g.nonce+"\nfacts="+g.facts_hash+"\nconnection="+connection+"\npid="+
            std::to_string(process_)+"\nstart="+start_+"\nintent_sha256="+hash(intents_.at(g.case_id).original.raw)+"\n";
        try { dir_->create("writer."+g.case_id+".original",body); guard(current_locked(g),"qa-private-diagnostic"); }
        catch (...) { held_=true; throw; }
    }
};

#ifdef __linux__
std::string system_file(const char* name,size_t maximum) {
    Fd fd(open(name,O_RDONLY|O_CLOEXEC|O_NONBLOCK)); return bounded(fd.fd,maximum);
}
std::string process_start() {
    auto raw=system_file("/proc/self/stat",8192); auto end=raw.rfind(')');
    guard(end!=std::string::npos && raw.substr(0,raw.find(' '))==std::to_string(getpid()),"qa-native-process");
    std::istringstream in(raw.substr(end+1)); std::string value;
    for (int n=0;n<20;++n) guard(bool(in>>value),"qa-native-process");
    number(value); return value;
}
std::string boot_id() {
    auto raw=system_file("/proc/sys/kernel/random/boot_id",128);
    guard(raw.size()==37 && raw.back()=='\n',"qa-native-boot"); return raw.substr(0,36);
}
std::string real(const char* path) {
    char buffer[PATH_MAX]; guard(realpath(path,buffer)!=nullptr,"qa-native-path"); return buffer;
}
File image(const std::string& path,bool contents) {
    auto slash=path.rfind('/'); canonical(path); guard(slash!=std::string::npos && slash>0,"qa-native-image");
    Fd dir(directory_fd(path.substr(0,slash),Owner{0,0},false));
    Fd fd(openat(dir.fd,path.substr(slash+1).c_str(),O_RDONLY|O_NOFOLLOW|O_CLOEXEC|O_NONBLOCK));
    struct stat a,b;
    guard(fstat(fd.fd,&a)==0 && S_ISREG(a.st_mode) && a.st_uid==0 && !(a.st_mode&0022) &&
          a.st_nlink==1 && a.st_size>0 && a.st_size<=16777216,"qa-native-image");
    auto raw=contents?bounded(fd.fd,16777216):std::string();
    guard(fstat(fd.fd,&b)==0 && stamp(a)==stamp(b),"qa-native-image-changed"); return {raw,stamp(a)};
}
File executable_image(const std::string& path,bool contents) {
    try {
        ProgramImage program(path,0,0);auto raw=contents?bounded(program.descriptor(),16777216):std::string();program.unchanged();
        return {raw,stamp(program.status()),program.binding()};
    }catch(const ProgramImageError&){throw ProbeError("qa-native-program-image");}
}

void mapped(uintptr_t address,const std::string& path,const Stamp& file) {
    std::istringstream lines(system_file("/proc/self/maps",1048576)); std::string line;
    while (std::getline(lines,line)) {
        std::istringstream in(line); std::string range,permissions,offset,device,ino,name;
        if (!(in>>range>>permissions>>offset>>device>>ino)) continue;
        auto dash=range.find('-'),colon=device.find(':');
        guard(dash!=std::string::npos && colon!=std::string::npos,"qa-native-mapping");
        auto first=std::stoull(range.substr(0,dash),nullptr,16),last=std::stoull(range.substr(dash+1),nullptr,16);
        if (address<first || address>=last) continue;
        std::getline(in,name); name.erase(0,name.find_first_not_of(' '));
        guard(name==path && permissions=="r-xp" && number(ino)==static_cast<uint64_t>(file.ino) &&
            std::stoull(device.substr(0,colon),nullptr,16)==major(file.dev) &&
            std::stoull(device.substr(colon+1),nullptr,16)==minor(file.dev),"qa-native-mapping"); return;
    }
    throw ProbeError("qa-native-mapping");
}
#endif
}

std::shared_ptr<const FrameworkAuthority> OpenProtectedFrameworkAuthority(const std::string& path) {
#ifdef __linux__
    guard(geteuid()==0 && getegid()==0,"qa-native-root-required");
    auto dir=std::make_shared<PrivateDirectory>(path,Owner{0,0});
    struct statfs fs; guard(fstatfs(dir->descriptor(),&fs)==0 &&
        (fs.f_type==0xEF53 || fs.f_type==0x58465342 || fs.f_type==0x01021994),"qa-native-filesystem");
    auto raw=dir->read("authority.v1",4096); auto base=lease(raw);
    Dl_info info; uintptr_t address=reinterpret_cast<uintptr_t>(&OpenProtectedFrameworkAuthority);
    guard(dladdr(reinterpret_cast<void*>(address),&info)!=0 && info.dli_fname,"qa-native-module");
    auto module=real(info.dli_fname),executable=real("/proc/self/exe"),boot=boot_id(),start=process_start();
    guard(module!=executable && module==base.fields.at("module_path") && executable==base.fields.at("runtime_path") &&
          boot==base.fields.at("boot"),"qa-native-binding");
    auto mod=image(module,true),exe=executable_image(executable,true);
    guard(hash(mod.raw)==base.fields.at("module_sha256") && hash(exe.raw)==base.fields.at("runtime_sha256"),"qa-native-build");
    mapped(address,module,mod.identity); pid_t process=getpid();
    auto mod_stamp=mod.identity,exe_stamp=exe.identity;auto exe_binding=exe.program_binding;
    auto check=[module,executable,boot,start,mod_stamp,exe_stamp,exe_binding,address,process] {
        if (geteuid()!=0 || getegid()!=0 || getpid()!=process || boot_id()!=boot || process_start()!=start ||
            real("/proc/self/exe")!=executable) return false;
        auto current_exe=executable_image(executable,false);
        if (!(image(module,false).identity==mod_stamp) || !(current_exe.identity==exe_stamp) || current_exe.program_binding!=exe_binding) return false;
        mapped(address,module,mod_stamp); return true;
    };
    auto result=std::make_shared<ProtectedAuthority>(dir,raw,start,check);
    return result;
#else
    (void)path; throw ProbeError("qa-native-linux-required");
#endif
}
}

#endif
