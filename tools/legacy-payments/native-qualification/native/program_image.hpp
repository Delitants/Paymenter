// Immutable program references only. Private control files use stricter custody.
#pragma once
#include <algorithm>
#include <climits>
#include <fcntl.h>
#include <memory>
#include <sstream>
#include <stdexcept>
#include <string>
#include <sys/stat.h>
#include <unistd.h>
#include <vector>
namespace paymenter_qa {
class ProgramImageError:public std::runtime_error {public:explicit ProgramImageError(const char*s):std::runtime_error(s){}};
inline void program_require(bool v,const char*s){if(!v)throw ProgramImageError(s);}
inline std::string program_file_identity(const struct stat& s) {
#ifdef __APPLE__
    auto mt=s.st_mtimespec,ct=s.st_ctimespec;
#else
    auto mt=s.st_mtim,ct=s.st_ctim;
#endif
    return std::to_string(s.st_dev)+":"+std::to_string(s.st_ino)+":"+std::to_string(s.st_mode)+":"+
        std::to_string(s.st_uid)+":"+std::to_string(s.st_gid)+":"+std::to_string(s.st_size)+":"+
        std::to_string(mt.tv_sec)+":"+std::to_string(mt.tv_nsec)+":"+std::to_string(ct.tv_sec)+":"+std::to_string(ct.tv_nsec)+":"+std::to_string(s.st_nlink);
}
inline void program_canonical(const std::string& p) {
    program_require(!p.empty() && p.size()<4096 && p[0]=='/' && (p=="/" || p.back()!='/') && p.find("//")==std::string::npos,"program-path");
    std::istringstream in(p.substr(1));std::string part;
    while(std::getline(in,part,'/'))program_require(part!="." && part!=".." && part.size()<=255,"program-path");
    for(unsigned char c:p)program_require(c>32 && c<127 && c!='\\',"program-path");
}
class ProgramFd {
public:int value;
    explicit ProgramFd(int fd=-1):value(fd){}
    ~ProgramFd(){if(value>=0)close(value);}
    ProgramFd(const ProgramFd&)=delete;ProgramFd& operator=(const ProgramFd&)=delete;
    void reset(int fd){if(value>=0)close(value);value=fd;}
};
class ProgramChain {
    struct Entry {std::shared_ptr<ProgramFd> fd;std::string name;struct stat original;};
    uid_t owner_;std::vector<Entry> entries_;
    static bool equal(const struct stat&a,const struct stat&b){return a.st_dev==b.st_dev && a.st_ino==b.st_ino && a.st_mode==b.st_mode && a.st_uid==b.st_uid && a.st_gid==b.st_gid;}
    void safe(const struct stat&s)const{program_require(S_ISDIR(s.st_mode) && (s.st_uid==0 || s.st_uid==owner_) && !(s.st_mode&0022),"program-parent");}
public:
    ProgramChain(const std::string& path,uid_t owner):owner_(owner) {
        program_canonical(path);auto fd=std::make_shared<ProgramFd>(open("/",O_RDONLY|O_DIRECTORY|O_CLOEXEC));struct stat s;
        program_require(fd->value>=0 && fstat(fd->value,&s)==0,"program-parent");safe(s);entries_.push_back({fd,"/",s});
        if(path!="/") {
            std::istringstream parts(path.substr(1));std::string part;
            while(std::getline(parts,part,'/')) {
                program_require(entries_.size()<65,"program-depth");auto child=std::make_shared<ProgramFd>(openat(entries_.back().fd->value,part.c_str(),O_RDONLY|O_DIRECTORY|O_NOFOLLOW|O_CLOEXEC));
                program_require(child->value>=0 && fstat(child->value,&s)==0,"program-parent");safe(s);entries_.push_back({child,part,s});
            }
        }
        unchanged();
    }
    int descriptor()const{return entries_.back().fd->value;}
    void unchanged()const {
        struct stat held,named;program_require(lstat("/",&named)==0 && equal(named,entries_.front().original),"program-parent-changed");
        for(size_t i=0;i<entries_.size();++i){const auto&e=entries_[i];program_require(fstat(e.fd->value,&held)==0 && equal(held,e.original),"program-parent-changed");safe(held);
            if(i)program_require(fstatat(entries_[i-1].fd->value,e.name.c_str(),&named,AT_SYMLINK_NOFOLLOW)==0 && equal(named,e.original),"program-parent-changed");}
    }
    std::string binding()const {
        std::string raw;
        for(const auto&e:entries_)raw+=e.name+":"+std::to_string(e.original.st_dev)+":"+std::to_string(e.original.st_ino)+":"+
            std::to_string(e.original.st_mode)+":"+std::to_string(e.original.st_uid)+":"+std::to_string(e.original.st_gid)+"\n";
        return raw;
    }
};
class ProgramImage {
    const std::string logical_;const uid_t uid_;const gid_t gid_;
    std::string target_,leaf_,target_leaf_,text_;bool alias_=false;
    std::unique_ptr<ProgramChain> logical_chain_,target_chain_;std::vector<std::unique_ptr<ProgramChain>> resolution_chains_;ProgramFd fd_,leaf_handle_;struct stat leaf_stamp_,target_stamp_;
    static std::string parent(const std::string&p){auto at=p.rfind('/');return at==0?"/":p.substr(0,at);}
    static std::string leaf(const std::string&p){return p.substr(p.rfind('/')+1);}
    std::string normalize(const std::string&p) {
        std::vector<std::string> parts;std::istringstream in(p);std::string part;unsigned steps=0;
        while(std::getline(in,part,'/')) {
            program_require(++steps<=65,"program-alias-depth");if(part.empty())continue;
            if(part=="." || part=="..") {
                std::string traversed;for(const auto&v:parts)traversed+="/"+v;
                program_require(resolution_chains_.size()<16,"program-alias-depth");
                resolution_chains_.emplace_back(new ProgramChain(traversed.empty()?"/":traversed,uid_));
                if(part==".."){program_require(!parts.empty(),"program-alias-target");parts.pop_back();}
            } else parts.push_back(part);
        }
        std::string out;for(const auto&v:parts)out+="/"+v;program_canonical(out);return out;
    }
    std::string link_text()const {char b[4096];auto n=readlinkat(logical_chain_->descriptor(),leaf_.c_str(),b,sizeof(b));program_require(n>0 && n<static_cast<ssize_t>(sizeof(b)),"program-alias");return std::string(b,n);}
public:
    ProgramImage(const std::string& path,uid_t uid,gid_t gid):logical_(path),uid_(uid),gid_(gid) {
        program_canonical(path);program_require(path!="/","program-path");leaf_=leaf(path);logical_chain_.reset(new ProgramChain(parent(path),uid));
        program_require(fstatat(logical_chain_->descriptor(),leaf_.c_str(),&leaf_stamp_,AT_SYMLINK_NOFOLLOW)==0,"program-leaf");
        alias_=S_ISLNK(leaf_stamp_.st_mode);target_=path;
        if(alias_) {
            program_require(leaf_stamp_.st_uid==uid_ && leaf_stamp_.st_gid==gid_ && leaf_stamp_.st_nlink==1,"program-alias-owner");text_=link_text();
            program_require(text_.back()!='/' && text_.find("//")==std::string::npos,"program-alias-target");
#ifdef __linux__
            int flags=O_PATH|O_NOFOLLOW|O_CLOEXEC;
#elif defined(O_SYMLINK)
            int flags=O_RDONLY|O_SYMLINK|O_CLOEXEC;
#else
            throw ProgramImageError("program-alias-platform");
#endif
            leaf_handle_.reset(openat(logical_chain_->descriptor(),leaf_.c_str(),flags));struct stat held_leaf;
            program_require(leaf_handle_.value>=0 && fstat(leaf_handle_.value,&held_leaf)==0 && program_file_identity(held_leaf)==program_file_identity(leaf_stamp_),"program-alias");
            for(unsigned char c:text_)program_require(c>32 && c<127 && c!='\\',"program-alias-target");
            target_=normalize(text_[0]=='/'?text_:parent(path)+"/"+text_);
        }
        target_leaf_=leaf(target_);target_chain_.reset(new ProgramChain(parent(target_),uid));struct stat named;
        program_require(fstatat(target_chain_->descriptor(),target_leaf_.c_str(),&named,AT_SYMLINK_NOFOLLOW)==0 && S_ISREG(named.st_mode),"program-target");
        fd_.reset(openat(target_chain_->descriptor(),target_leaf_.c_str(),O_RDONLY|O_NOFOLLOW|O_NONBLOCK|O_CLOEXEC));
        program_require(fd_.value>=0 && fstat(fd_.value,&target_stamp_)==0 && program_file_identity(named)==program_file_identity(target_stamp_),"program-target");
        program_require(target_stamp_.st_uid==uid_ && target_stamp_.st_gid==gid_ && !(target_stamp_.st_mode&0022) && (target_stamp_.st_mode&0111) &&
            target_stamp_.st_nlink>0 && target_stamp_.st_size>0 && target_stamp_.st_size<=16777216,"program-target");unchanged();
    }
    int descriptor()const{return fd_.value;}
    const struct stat& status()const{return target_stamp_;}
    bool alias()const{return alias_;}
    const std::string& target_path()const{return target_;}
    void unchanged()const {
        logical_chain_->unchanged();target_chain_->unchanged();for(const auto& chain:resolution_chains_)chain->unchanged();struct stat leaf_now,target_now,held;
        program_require(fstatat(logical_chain_->descriptor(),leaf_.c_str(),&leaf_now,AT_SYMLINK_NOFOLLOW)==0 && program_file_identity(leaf_now)==program_file_identity(leaf_stamp_),"program-leaf-changed");
        if(alias_){struct stat held_leaf;program_require(fstat(leaf_handle_.value,&held_leaf)==0 && program_file_identity(held_leaf)==program_file_identity(leaf_stamp_) && link_text()==text_,"program-alias-changed");}
        program_require(fstatat(target_chain_->descriptor(),target_leaf_.c_str(),&target_now,AT_SYMLINK_NOFOLLOW)==0 && fstat(fd_.value,&held)==0 &&
            program_file_identity(target_now)==program_file_identity(target_stamp_) && program_file_identity(held)==program_file_identity(target_stamp_),"program-target-changed");
    }
    std::string binding()const {
        auto raw="paymenter-program-reference-v1\nlogical_path="+logical_+"\ntarget_path="+target_+"\nlink_text="+(alias_?text_:"none")+"\nlogical_identity="+
            program_file_identity(leaf_stamp_)+"\ntarget_identity="+program_file_identity(target_stamp_)+"\nlogical_parents\n"+logical_chain_->binding()+"target_parents\n"+target_chain_->binding();
        for(const auto& chain:resolution_chains_)raw+="resolution_parents\n"+chain->binding();
        program_require(raw.size()<=16384,"program-reference-bound");return raw;
    }
};
}
