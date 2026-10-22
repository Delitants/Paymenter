#include "../native/program_image.hpp"
#include <fstream>
#include <iostream>
using namespace paymenter_qa;
void check(bool v){if(!v)throw std::runtime_error("assertion");}
template<class F> void refuses(F f){bool held=false;try{f();}catch(const ProgramImageError&){held=true;}check(held);}
void copy(const std::string& from,const std::string& to){std::ifstream in(from,std::ios::binary);std::ofstream out(to,std::ios::binary);out<<in.rdbuf();out.close();check(bool(out) && chmod(to.c_str(),0755)==0);}
int main(int argc,char**argv){try{
    check(argc==3);std::string mode=argv[1],root=argv[2],target=root+"/program",alias=root+"/logical",selected=target;
    char actual[4096];check(realpath(argv[0],actual)!=nullptr);copy(actual,target);
    if(mode=="hardlinked" || mode=="link-count-change")check(link(target.c_str(),(root+"/other").c_str())==0);
    bool aliased=mode.find("alias")==0 || mode=="retarget" || mode=="target-change" || mode=="dangling" || mode=="loop" || mode=="target-symlink" || mode=="target-parent-symlink" || mode=="writable-target-parent" || mode=="alias-hardlinked";
    if(aliased){selected=alias;std::string text=mode=="alias-absolute"?target:"program";check(symlink(text.c_str(),alias.c_str())==0);}
    if(mode=="writable-target")check(chmod(target.c_str(),0775)==0);
    if(mode=="writable-parent")check(chmod(root.c_str(),0777)==0);
    if(mode=="noncanonical")selected=root+"/./program";
    if(mode=="dangling"){check(unlink(target.c_str())==0);}
    if(mode=="loop"){check(unlink(alias.c_str())==0 && symlink("logical",alias.c_str())==0);}
    if(mode=="target-symlink"){check(rename(target.c_str(),(root+"/real").c_str())==0 && symlink("real",target.c_str())==0);}
    if(mode=="parent-symlink"){check(mkdir((root+"/real").c_str(),0755)==0 && rename(target.c_str(),(root+"/real/program").c_str())==0 && symlink("real",(root+"/directory").c_str())==0);selected=root+"/directory/program";}
    if(mode=="alias-hardlinked")check(linkat(AT_FDCWD,alias.c_str(),AT_FDCWD,(root+"/alias-link").c_str(),0)==0);
    if(mode=="writable-target-parent" || mode=="target-parent-symlink") {
        check(mkdir((root+"/real").c_str(),0755)==0 && rename(target.c_str(),(root+"/real/program").c_str())==0 && unlink(alias.c_str())==0);
        if(mode=="writable-target-parent"){check(chmod((root+"/real").c_str(),0777)==0 && symlink("real/program",alias.c_str())==0);}
        else check(symlink("real",(root+"/directory").c_str())==0 && symlink("directory/program",alias.c_str())==0);
    }
    if(mode=="alias-cancelled-symlink" || mode=="alias-cancelled-missing" || mode=="alias-file-dot" || mode=="alias-trailing-slash" || mode=="alias-removed-parent-change") {
        check(unlink(alias.c_str())==0);std::string text;
        if(mode=="alias-cancelled-symlink"){check(symlink("/",(root+"/skipped").c_str())==0);text="skipped/../program";}
        else if(mode=="alias-cancelled-missing")text="missing/../program";
        else if(mode=="alias-file-dot")text="program/.";
        else if(mode=="alias-trailing-slash")text="program/";
        else {check(mkdir((root+"/skipped").c_str(),0755)==0);text="skipped/../program";}
        check(symlink(text.c_str(),alias.c_str())==0);
    }
    bool bad=mode=="writable-target" || mode=="writable-parent" || mode=="noncanonical" || mode=="dangling" || mode=="loop" || mode=="target-symlink" || mode=="parent-symlink" || mode=="wrong-owner" || mode=="target-parent-symlink" || mode=="writable-target-parent" || mode=="alias-hardlinked";
    bad=bad || mode=="alias-cancelled-symlink" || mode=="alias-cancelled-missing" || mode=="alias-file-dot" || mode=="alias-trailing-slash";
    if(bad){refuses([&]{ProgramImage p(selected,mode=="wrong-owner"?getuid()+1:getuid(),getgid());});if(mode=="writable-parent")check(chmod(root.c_str(),0700)==0);return 0;}
    ProgramImage p(selected,getuid(),getgid());check(p.target_path()==target && p.alias()==aliased && !p.binding().empty());p.unchanged();
    if(mode=="alias-replaced"){check(rename(alias.c_str(),(root+"/old-alias").c_str())==0 && symlink("program",alias.c_str())==0);}
    else if(mode=="retarget"){copy(target,root+"/replacement");check(unlink(alias.c_str())==0 && symlink("replacement",alias.c_str())==0);}
    else if(mode=="link-count-change")check(unlink((root+"/other").c_str())==0);
    else if(mode=="target-change"){check(rename(target.c_str(),(root+"/old-target").c_str())==0);copy(root+"/old-target",target);}
    else if(mode=="parent-change"){check(rename(root.c_str(),(root+".old").c_str())==0 && mkdir(root.c_str(),0700)==0);}
    else if(mode=="alias-removed-parent-change"){check(rename((root+"/skipped").c_str(),(root+"/skipped-old").c_str())==0 && mkdir((root+"/skipped").c_str(),0755)==0);}
    else if(mode=="target-same-bytes"){
        // One rewrite after an observable clock tick; stat is not a write-event log.
        usleep(20000);int fd=open(target.c_str(),O_RDWR);char value;check(fd>=0 && read(fd,&value,1)==1 && pwrite(fd,&value,1,0)==1);close(fd);}
    else if(mode=="target-mode-change")check(chmod(target.c_str(),0700)==0);
    else return 0;
    refuses([&]{p.unchanged();});return 0;
}catch(const std::exception&e){std::cerr<<e.what()<<'\n';return 1;}}
