#define PAYMENTER_QA_MANUAL_READER_NO_MAIN
#include "../native/manual_financial_reader.cpp"
#include <iostream>
int main(int argc,char**argv){using namespace paymenter_qa;try{
    if(argc!=3)return 2;std::string mode=argv[1],root=argv[2];manual_mysql_mode=mode;manual_mysql_root=root;
    auto dir=std::make_shared<PrivateDirectory>(root,Owner{getuid(),getgid()});
    ManualReadImages images(root+"/runtime",root+"/library",root+"/reader",Owner{getuid(),getgid()});
    bool accepted=false;unsigned checks=0;
    auto current_images=[&](){
        if(++checks==3 && mode=="image-change"){FILE* f=fopen((root+"/library").c_str(),"w");if(f){fputs("changed",f);fclose(f);}}
        if(checks==3 && mode=="credential-change"){chmod((root+"/credential").c_str(),0600);FILE* f=fopen((root+"/credential").c_str(),"w");if(f){fputs("[client]\npassword=changed\n",f);fclose(f);}}
        if(checks==3 && mode=="approval-change"){auto f=dir->read("manual.read.approval.original",4096);rename((root+"/manual.read.approval.original").c_str(),(root+"/old-approval").c_str());dir->create("manual.read.approval.original",f.raw);}
        if(checks==10 && mode=="late-schema-change"){FILE* f=fopen((root+"/manual.schema.payment.original").c_str(),"w");if(f){fputs("changed",f);fclose(f);}}
        if((mode=="after-original-revoke" && dir->exists("manual.read.original")) || (mode=="after-complete-revoke" && dir->exists("manual.read.complete.original"))){if(!dir->exists("revoked.v1"))dir->create("revoked.v1","revoked\n");}
        if(mode=="lost-completion" && dir->exists("manual.read.original") && !dir->exists("manual.read.complete.original"))mkdir((root+"/manual.read.complete.original").c_str(),0700);
        return images.current();
    };
    try{perform_manual_financial_read(dir,root+(mode=="credential-symlink"?"/credential-link":"/credential"),Owner{getuid(),getgid()},"qa","/fictional.sock",[]{return "11111111-1111-1111-1111-111111111111";},current_images);accepted=true;}
    catch(const ProbeError&){}catch(const ReaderError&){}catch(const ProgramImageError&){}
    bool wanted=mode=="valid" || mode=="M5";
    if(mode=="legacy-limit"){try{dir->create("legacy-large.original",std::string(4097,'x'));return 8;}catch(const ProbeError&){}wanted=true;}
    if(accepted!=wanted)return 3;
    if(mode=="replay" || mode=="prior-original" || mode=="prior-completion" || mode=="prior-failure" || mode=="expired" || mode=="wrong-boot" || mode=="wrong-intent" || mode=="registry-hash" || mode=="identity-hash" || mode=="bad-writer" || mode=="schema-hash")if(manual_mysql_connections)return 4;
    return 0;
}catch(const std::exception&e){std::cerr<<e.what();return 1;}}
