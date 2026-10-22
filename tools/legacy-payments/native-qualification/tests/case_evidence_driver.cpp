#define PAYMENTER_QA_EVIDENCE_NO_MAIN
#include "../native/case_evidence.cpp"
#include <fstream>
#include <iostream>
using namespace paymenter_qa;
static void check(bool v){if(!v)throw std::runtime_error("test assertion");}
template<class F>void refuses(F f){bool failed=false;try{f();}catch(const ProbeError&){failed=true;}check(failed);}
int main(int argc,char**argv) {
 try {
    check(argc==3);std::string mode=argv[1],root=argv[2];Owner owner{getuid(),getgid()};
    auto dir=std::make_shared<PrivateDirectory>(root,owner);
    auto boot=[]{return "11111111-1111-1111-1111-111111111111";};
    auto images=[&](const std::string& p){return dir->read(p.substr(p.rfind('/')+1),16777216);};
    bool target=true;
    TargetCheck target_check=[&](pid_t p,const std::string& start,const Fields&){return target && p==2 && start=="99";};
    NativeControlIssuer issuer(dir,boot,images,target_check);issuer.issue_authority();
    std::string id=mode=="f2"?"F2":mode=="f3"?"F3":mode.find("f4")==0?"F4":"F1";
    auto auth=dir->read("authority.v1",4096);auto lease_fields=lease(auth).fields;auto now=now_seconds();
    Fields f{{"intent","issue-framework-case"},{"run",std::string(64,'a')},{"case",id},{"nonce",lease_fields.at("nonce_"+id)},
        {"facts",std::string(64,'c')},{"pid","2"},{"start","99"},{"boot",boot()},{"issued",std::to_string(now-2)},
        {"expires",std::to_string(now+25)},{"authority_sha256",hash(auth.raw)}};
    dir->create("case."+id+".approval.native.original",encode_fields("paymenter-source-qa-approve-case-native-v1",f,"intent run case nonce facts pid start boot issued expires authority_sha256"));
    issuer.issue_case(id);auto intent=dir->read("intent.v1",4096);
    Fields w{{"run",f.at("run")},{"case",id},{"nonce",f.at("nonce")},{"facts",f.at("facts")},{"connection","11"},
        {"pid","2"},{"start","99"},{"intent_sha256",mode=="wrong-intent"?std::string(64,'f'):hash(intent.raw)}};
    if(mode=="wrong-writer")w["nonce"]=std::string(64,'e');
    dir->create("writer."+id+".original",encode_fields("paymenter-source-qa-writer-v1",w,"run case nonce facts connection pid start intent_sha256"));
    bool barrier=mode=="f4-barrier";std::string phase=barrier?"barrier":"after";
    auto make_read=[&](const std::string& slot,const std::string& reader,bool changed) {
        auto path=root+"/read."+id+"."+slot;check(mkdir(path.c_str(),0700)==0);
        auto rd=std::make_shared<PrivateDirectory>(path,owner);auto request=std::string(64,slot=="after"?'b':'d');
        Fields a{{"intent","observe-framework"},{"kind","observation"},{"run",f.at("run")},{"request",request},{"boot",boot()},
            {"case",id},{"phase",phase},{"facts",f.at("facts")},{"writer","11"},{"previous","none"},
            {"issued",std::to_string(now-1)},{"expires",std::to_string(now+20)}};
        auto approval=encode_fields("paymenter-source-qa-read-approval-native-v1",a,"intent kind run request boot case phase facts writer previous issued expires");
        rd->create("read.approval.native.original",approval);
        rd->create("read.started.original","paymenter-source-qa-read-started-native-v1\nrequest="+request+"\napproval_sha256="+hash(approval)+"\n");
        Fields o{{"kind","observation"},{"run",f.at("run")},{"request",mode=="wrong-request"?std::string(64,'e'):request},{"boot",boot()},
            {"case",id},{"phase",phase},{"facts",f.at("facts")},{"writer","11"},{"connection",reader},
            {"started",std::to_string(mode=="stale-read" || (changed && mode=="recovery-earlier")?now-10:now)},
            {"finished",std::to_string(now)},{"isolation","READ COMMITTED"},{"autocommit","1"},{"transaction_open","0"},{"approval_sha256",hash(approval)}};
        std::string raw=encode_fields("paymenter-source-qa-read-original-native-v1",o,"kind run request boot case phase facts writer connection started finished isolation autocommit transaction_open approval_sha256");
        bool empty=id=="F2" || id=="F3" || barrier || (changed && mode=="changed-rows");
        if(!empty){raw+="row=business,"+f.at("run")+","+id+",0.0100,"+f.at("facts")+"\n";
            if(mode!="split")raw+="row=journal,"+f.at("run")+","+id+","+id+","+f.at("facts")+"\n";}
        rd->create("read.original",raw);
        rd->create("read.complete.original","paymenter-source-qa-read-complete-native-v1\nrequest="+request+"\noriginal_sha256="+(mode=="completion-hash"?std::string(64,'f'):hash(raw))+"\n");
        if(mode=="read-failure")rd->create("read.failure.original","incomplete\n");
        if(mode=="read-symlink"){check(unlink((path+"/read.original").c_str())==0);check(symlink("../authority.v1",(path+"/read.original").c_str())==0);}
    };
    make_read(barrier?"barrier":"after",mode=="same-reader"?"11":"12",false);
    auto caller=[&] {
        std::string stdout_raw="fictional SDK caller reply\n",stderr_raw=mode=="caller-stderr"?"error\n":"";
        dir->create("caller."+id+".stdout",stdout_raw);
        // PrivateDirectory deliberately rejects empty originals; capture stores
        // a length-bound encoded payload, including empty stderr as zero bytes.
        Fields c{{"run",f.at("run")},{"case",id},{"boot",boot()},{"pid","3"},{"start","100"},{"exit","0"},
            {"stdout_eof",mode=="caller-partial"?"0":"1"},{"stderr_eof","1"},{"stdout_sha256",hash(stdout_raw)},
            {"stderr_bytes",std::to_string(stderr_raw.size())},{"delivery",mode=="response-delivered"?"delivered":"withheld"},
            {"nonce",mode=="caller-wrong-nonce"?std::string(64,'e'):f.at("nonce")},{"facts",f.at("facts")},
            {"intent_sha256",mode=="caller-wrong-intent"?std::string(64,'f'):hash(intent.raw)}};
        dir->create("caller."+id+".original",encode_fields("paymenter-source-qa-caller-capture-v1",c,"run case boot pid start exit stdout_eof stderr_eof stdout_sha256 stderr_bytes delivery nonce facts intent_sha256"));
    };
    FrameworkCaseEvidence evidence(dir,owner,boot,images,target_check,
        [&](pid_t p,const std::string& start){check(p==3 && start=="100");return mode!="caller-still-alive";});
    if(mode=="target-changed")target=false;
    bool binding_bad=mode=="split" || mode=="wrong-writer" || mode=="wrong-intent" || mode=="wrong-request" || mode=="same-reader" ||
        mode=="read-failure" || mode=="completion-hash" || mode=="stale-read" || mode=="target-changed" || mode=="read-symlink";
    if(binding_bad){refuses([&]{evidence.bind(id,barrier?"barrier":"after");});check(!dir->exists("observed."+id+"."+phase+".original"));return 0;}
    evidence.bind(id,barrier?"barrier":"after");
    if(mode=="bind-replay"){refuses([&]{evidence.bind(id,"after");});return 0;}
    if(mode=="f1" || mode=="f2" || mode=="f3" || mode=="f4-barrier" || mode=="f4-after")return 0;
    caller();
    if(mode=="caller-still-alive" || mode=="caller-stderr" || mode=="caller-partial" || mode=="response-delivered" || mode=="caller-wrong-intent" || mode=="caller-wrong-nonce"){
        refuses([&]{evidence.withhold(id);});check(!dir->exists("withheld."+id+".original"));return 0;}
    if(mode=="preexisting-fresh")make_read("reconcile","13",true);
    if(mode!="missing-withheld")evidence.withhold(id);
    if(mode!="preexisting-fresh")make_read("reconcile",mode=="reused-reader"?"12":"13",true);
    if(mode=="changed-original") {std::ofstream out(root+"/read.F1.after/read.original");out<<"changed original\n";}
    if(mode=="replaced-original" || mode=="replaced-writer" || mode=="replaced-authority") {
        auto name=mode=="replaced-original"?"read.F1.after/read.original":mode=="replaced-writer"?"writer.F1.original":"authority.v1";
        std::ifstream in(root+"/"+name);std::stringstream raw;raw<<in.rdbuf();in.close();
        check(unlink((root+"/"+name).c_str())==0);std::ofstream out(root+"/"+name);out<<raw.str();out.close();
        check(chmod((root+"/"+name).c_str(),0600)==0);
    }
    if(mode!="lost-recovered"){
        refuses([&]{evidence.recover(id);});check(!dir->exists("recovered."+id+".original"));return 0;}
    evidence.recover(id);check(dir->exists("recovered.F1.original"));
 }catch(const std::exception&e){std::cerr<<e.what()<<'\n';return 1;}
}
