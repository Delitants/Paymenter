// Standalone native control publisher; not linked into the SDK module.
// Amalgamation deliberately shares the exact protected-file/parser/image logic.
// Never compile this TU together with authority_bridge.cpp into the same binary.
#include "authority_bridge.cpp"
#include <iostream>
namespace paymenter_qa {
namespace {
const char* const AUTH_FIELDS="intent run boot actor_hex issued expires module_path module_sha256 runtime_path runtime_sha256 registry_sha256 build_sha256 nonce_F1 facts_F1 nonce_F2 facts_F2 nonce_F3 facts_F3 nonce_F4 facts_F4";
const char* const REGISTRY_DIAGNOSTICS="writer.F1.original,writer.F2.original,writer.F3.original,writer.F4.original";
std::string encode_fields(const std::string& purpose,const Fields& fields,const std::string& order) {
    std::string raw=purpose+"\n",key;std::istringstream names(order);
    while(names>>key) raw+=key+"="+fields.at(key)+"\n";
    guard(raw.size()<=4096,"qa-native-control-size");return raw;
}
void remove_fence(const std::shared_ptr<PrivateDirectory>& dir,const File& original) {
    guard(same_file(original,dir->read("revoked.v1",4096)),"qa-native-fence-changed");
    dir->unchanged();guard(unlinkat(dir->descriptor(),"revoked.v1",0)==0,"qa-native-fence-remove");
    if(fsync(dir->descriptor())!=0) {
        try {if(!dir->exists("revoked.v1"))dir->create("revoked.v1",original.raw);}catch(...){}
        throw ProbeError("qa-native-fence-sync");
    }
    dir->unchanged();
}
using ImageReader=std::function<File(const std::string&)>;
using TargetCheck=std::function<bool(pid_t,const std::string&,const Fields&)>;
struct NativeContext {
    std::shared_ptr<PrivateDirectory> dir;
    std::function<std::string()> boot;
    ImageReader images;
    File approval,registry,build,module,runtime;
    Fields fields;
    Lease admitted;
    Clock::time_point deadline;
    static File execution(const File& approval) {
        auto f=parse(approval.raw,"paymenter-source-qa-approve-authority-native-v1",AUTH_FIELDS);
        guard(f.at("intent")=="issue-framework-authority","qa-native-approval-intent");
        f["intent"]="framework-qa-execute";
        return {encode_fields("paymenter-source-qa-authority-v1",f,AUTH_FIELDS),approval.identity};
    }
    NativeContext(std::shared_ptr<PrivateDirectory> directory,std::function<std::string()> kernel_boot,ImageReader reader):
        dir(directory),boot(kernel_boot),images(reader),approval(dir->read("approval.native.original",4096)),
        registry(dir->read("registry.original",4096)),build(dir->read("build.original",4096)),
        fields(lease(execution(approval)).fields),admitted(lease(execution(approval))) {
        auto now=now_seconds();guard(now>=admitted.issued && now<admitted.expires,"qa-native-expiry");
        deadline=Clock::now()+std::chrono::seconds(admitted.expires-now);
        auto r=parse(registry.raw,"paymenter-source-qa-registry-native-v1","run tables objects diagnostics");
        guard(r.at("run")==fields.at("run") && r.at("tables")=="paymenter_qa_business_v1,paymenter_qa_journal_v1" &&
            r.at("objects")=="none" && r.at("diagnostics")==REGISTRY_DIAGNOSTICS,"qa-native-registry");
        auto b=parse(build.raw,"paymenter-source-qa-build-native-v1","run module_path module_sha256 runtime_path runtime_sha256");
        guard(b.at("run")==fields.at("run"),"qa-native-build");
        for(const auto& key:{"module_path","module_sha256","runtime_path","runtime_sha256"})
            guard(b.at(key)==fields.at(key),"qa-native-build");
        guard(hash(registry.raw)==fields.at("registry_sha256") && hash(build.raw)==fields.at("build_sha256"),"qa-native-original-hash");
        module=images(fields.at("module_path"));runtime=images(fields.at("runtime_path"));
        guard(fields.at("module_path")!=fields.at("runtime_path") &&
            !(module.identity.dev==runtime.identity.dev && module.identity.ino==runtime.identity.ino),"qa-native-distinct-images");
        for(const auto& pair:{std::make_pair(&module,"module_sha256"),std::make_pair(&runtime,"runtime_sha256")})
            guard(pair.first->raw.compare(0,4,"\177ELF")==0 && hash(pair.first->raw)==fields.at(pair.second),"qa-native-image");
        check();
    }
    void check() const {
        dir->unchanged();auto now=now_seconds();
        guard(boot()==fields.at("boot"),"qa-native-boot");
        guard(now>=admitted.issued && now<admitted.expires && Clock::now()<deadline,"qa-native-expiry");
        guard(!dir->exists("retired.v1"),"qa-native-retired");
        guard(same_file(approval,dir->read("approval.native.original",4096)) &&
            same_file(registry,dir->read("registry.original",4096)) && same_file(build,dir->read("build.original",4096)),"qa-native-original-changed");
        guard(same_file(module,images(fields.at("module_path"))) && same_file(runtime,images(fields.at("runtime_path"))),"qa-native-image-changed");
    }
    std::string authority() const {return encode_fields("paymenter-source-qa-authority-v1",fields,AUTH_FIELDS);}
    std::string receipt() const {
        return "paymenter-source-qa-native-issuance-v1\nrun="+fields.at("run")+"\napproval_sha256="+
            hash(approval.raw)+"\nauthority_sha256="+hash(authority())+"\n";
    }
};
class NativeControlIssuer {
    const std::shared_ptr<PrivateDirectory> dir_;
    const std::function<std::string()> boot_;
    const ImageReader images_;
    const TargetCheck target_;
    const pid_t process_=getpid();
    void process() const {guard(getpid()==process_,"qa-native-process");}
public:
    NativeControlIssuer(std::shared_ptr<PrivateDirectory> dir,std::function<std::string()> boot,
                        ImageReader images,TargetCheck target):dir_(dir),boot_(boot),images_(images),target_(target) {}
    void issue_authority() const {
        process();
        for(const auto& name:{"authority.v1","intent.v1","issuance.original","issuance.native.original","revoked.v1","retired.v1","barrier.original","release.F4.v1"})
            guard(!dir_->exists(name),"qa-native-reused-operation");
        for(const auto& id:{"F1","F2","F3","F4"})
            guard(!dir_->exists(std::string("writer.")+id+".original") &&
                !dir_->exists(std::string("intent.")+id+".original"),"qa-native-reused-operation");
        NativeContext context(dir_,boot_,images_);
        dir_->create("revoked.v1","paymenter-source-qa-native-issuance-incomplete-v1\nrun="+context.fields.at("run")+"\n");
        auto fence=dir_->read("revoked.v1",4096);context.check();
        dir_->create("issuance.native.original",context.receipt());
        dir_->create("authority.v1",context.authority());
        guard(dir_->read("authority.v1",4096).raw==context.authority(),"qa-native-publish");
        context.check();remove_fence(dir_,fence);
    }
    void issue_case(const std::string& id) const {
        process();(void)case_mode(id);
        for(const auto& name:{"intent.v1","revoked.v1","retired.v1","barrier.original","release.F4.v1"})
            guard(!dir_->exists(name),"qa-native-reused-case");
        guard(!dir_->exists("intent."+id+".original") && !dir_->exists("writer."+id+".original"),"qa-native-reused-case");
        NativeContext context(dir_,boot_,images_);
        auto authority=dir_->read("authority.v1",4096),receipt=dir_->read("issuance.native.original",4096);
        guard(authority.raw==context.authority() && receipt.raw==context.receipt(),"qa-native-issued-original");
        const auto name="case."+id+".approval.native.original";
        auto original=dir_->read(name,4096);
        auto f=parse(original.raw,"paymenter-source-qa-approve-case-native-v1",
            "intent run case nonce facts pid start boot issued expires authority_sha256");
        guard(f.at("intent")=="issue-framework-case" && f.at("run")==context.fields.at("run") &&
            f.at("case")==id && f.at("boot")==context.fields.at("boot") &&
            f.at("nonce")==context.fields.at("nonce_"+id) && f.at("facts")==context.fields.at("facts_"+id) &&
            f.at("authority_sha256")==hash(authority.raw),"qa-native-case-binding");
        auto pid=number(f.at("pid")),start=number(f.at("start")),issued=number(f.at("issued")),expires=number(f.at("expires"));
        guard(pid>1 && pid<=INT_MAX && start<=(uint64_t)INT64_MAX,"qa-native-target");
        auto now=now_seconds();
        guard(issued<expires && expires-issued<=30 && issued>=context.admitted.issued &&
            expires<=context.admitted.expires && now>=issued && now<expires,"qa-native-case-expiry");
        auto end=Clock::now()+std::chrono::seconds(expires-now);
        auto current=[&] {
            context.check();guard(now_seconds()>=issued && now_seconds()<expires && Clock::now()<end,"qa-native-case-expiry");
            guard(same_file(original,dir_->read(name,4096)) && same_file(authority,dir_->read("authority.v1",4096)) &&
                same_file(receipt,dir_->read("issuance.native.original",4096)),"qa-native-original-changed");
            guard(target_(static_cast<pid_t>(pid),f.at("start"),context.fields),"qa-native-target");
        };
        current();dir_->create("revoked.v1","paymenter-source-qa-native-case-incomplete-v1\nrun="+f.at("run")+"\ncase="+id+"\n");
        auto fence=dir_->read("revoked.v1",4096);current();
        auto intent=encode_fields("paymenter-source-qa-intent-v1",f,"run case nonce facts pid start issued expires");
        dir_->create("intent."+id+".original",intent);dir_->create("intent.v1",intent);
        guard(dir_->read("intent.v1",4096).raw==intent && dir_->read("intent."+id+".original",4096).raw==intent,"qa-native-publish");
        current();remove_fence(dir_,fence);
    }
};
#ifdef __linux__
std::string target_start(pid_t pid) {
    auto raw=system_file(("/proc/"+std::to_string(pid)+"/stat").c_str(),8192);
    guard(raw.substr(0,raw.find(' '))==std::to_string(pid) && raw.rfind(')')!=std::string::npos,"qa-native-target");
    std::istringstream fields(raw.substr(raw.rfind(')')+1));std::string value;
    for(int i=0;i<20;++i)guard(bool(fields>>value),"qa-native-target");
    number(value);return value;
}
inline bool native_target(pid_t pid,const std::string& start,const Fields& fields) {
    if(boot_id()!=fields.at("boot") || target_start(pid)!=start)return false;
    const std::string base="/proc/"+std::to_string(pid);
    char exe[PATH_MAX];auto length=readlink((base+"/exe").c_str(),exe,sizeof(exe));
    if(length<=0 || std::string(exe,static_cast<size_t>(length))!=fields.at("runtime_path"))return false;
    auto runtime=image(fields.at("runtime_path"),false);struct stat actual;
    if(stat((base+"/exe").c_str(),&actual)!=0 || actual.st_dev!=runtime.identity.dev || actual.st_ino!=runtime.identity.ino)return false;
    std::istringstream status(system_file((base+"/status").c_str(),65536));std::string line;bool root=false;
    while(std::getline(status,line))if(line.compare(0,4,"Uid:")==0) {
        std::istringstream values(line.substr(4));std::string a,b,c,d,extra;
        root=bool(values>>a>>b>>c>>d) && !(values>>extra) && a=="0" && b=="0" && c=="0" && d=="0";
    }
    if(!root)return false;
    auto module=image(fields.at("module_path"),false);
    std::istringstream mappings(system_file((base+"/maps").c_str(),1048576));bool found=false;
    while(std::getline(mappings,line)) {
        std::istringstream parts(line);std::string range,perm,offset,device,inode,path;
        if(!(parts>>range>>perm>>offset>>device>>inode))continue;
        std::getline(parts,path);path.erase(0,path.find_first_not_of(' '));
        if(path!=fields.at("module_path") || perm!="r-xp")continue;
        auto colon=device.find(':');guard(colon!=std::string::npos,"qa-native-target");
        found=number(inode)==static_cast<uint64_t>(module.identity.ino) &&
            std::stoull(device.substr(0,colon),nullptr,16)==major(module.identity.dev) &&
            std::stoull(device.substr(colon+1),nullptr,16)==minor(module.identity.dev);
        if(found)break;
    }
    return found && target_start(pid)==start;
}
#endif
}
}
#ifndef PAYMENTER_QA_ISSUER_NO_MAIN
int main(int argc,char**argv) {
    try {
        using namespace paymenter_qa;
#ifdef __linux__
        guard(geteuid()==0 && getegid()==0,"qa-native-linux-root-required");
        guard(argc==3 || argc==4,"qa-native-arguments");
        auto dir=std::make_shared<PrivateDirectory>(argv[2],Owner{0,0});
        struct statfs fs;guard(fstatfs(dir->descriptor(),&fs)==0 &&
            (fs.f_type==0xEF53 || fs.f_type==0x58465342 || fs.f_type==0x01021994),"qa-native-filesystem");
        NativeControlIssuer issuer(dir,boot_id,[](const std::string& path){return image(path,true);},native_target);
        if(std::string(argv[1])=="authority" && argc==3){issuer.issue_authority();std::cout<<"native-authority-published\n";}
        else if(std::string(argv[1])=="case" && argc==4){issuer.issue_case(argv[3]);std::cout<<"native-case-published\n";}
        else throw ProbeError("qa-native-arguments");
        return 0;
#else
        (void)argc;(void)argv;throw ProbeError("qa-native-linux-root-required");
#endif
    }catch(const paymenter_qa::ProbeError& e){std::cerr<<e.what()<<'\n';return 1;}
    catch(...){std::cerr<<"qa-native-issuer-failed\n";return 1;}
}
#endif
