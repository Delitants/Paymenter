/* Portable modeled syscall boundary, never kernel confinement evidence. */
#include <string.h>
#include <unistd.h>
#include "../native/fixture_child_policy.h"

extern int fixture_install_filter(void);
extern int fixture_child_main(long,const char *const *);
static const char *mode;
static int stage,broken,writes;

long fixture_syscall6(long nr,long a,long b,long c,long d,long e,long f) {
    if(nr==157 && a==38 && b==1 && c==0 && d==0 && e==0 && f==0 && stage==0) {
        if(strcmp(mode,"no-new-privs-fail")==0) {stage=-1;return -1;}
        stage=1;return 0;
    }
    if(nr==157 && a==22 && b==2 && c!=0 && d==0 && e==0 && f==0 && stage==1) {
        const struct fixture_sock_fprog *program=(const struct fixture_sock_fprog *)c;
        if(program->len!=FIXTURE_FILTER_LEN || !program->filter ||
           memcmp(program->filter,fixture_filter,sizeof(fixture_filter))!=0) {
            broken=1;return -1;
        }
        if(strcmp(mode,"setup-fail")==0) {stage=-1;return -1;}
        stage=2;return 0;
    }
    if(nr==1 && stage==2 && ++writes==1 && b!=0 && d==0 && e==0 && f==0 &&
       ((a==1 && c==110) || (a==2 && c==27))) {
        int out_error=strcmp(mode,"stdout-error")==0;
        int out_short=strcmp(mode,"stdout-short")==0;
        int err_error=strcmp(mode,"stderr-error")==0;
        int err_short=strcmp(mode,"stderr-short")==0;
        if(((out_error || out_short) && a!=1) ||
           ((err_error || err_short) && a!=2)) {broken=1;return -1;}
        if(out_error || err_error) return -1;
        if(out_short || err_short) return c-1;
        return write((int)a,(const void *)b,(size_t)c);
    }
    broken=1;return -1;
}

int main(int argc,char **argv) {
    int result;
    if(argc<2) return 125;
    mode=argv[1];
    if(strcmp(mode,"normal") && strcmp(mode,"no-new-privs-fail") &&
       strcmp(mode,"setup-fail") && strcmp(mode,"stdout-error") &&
       strcmp(mode,"stdout-short") && strcmp(mode,"stderr-error") &&
       strcmp(mode,"stderr-short") && strcmp(mode,"argv0-null")) return 125;
    result=fixture_install_filter();
    if(broken) return 125;
    if(result!=0) return stage==-1 && writes==0 ? 126 : 125;
    if(stage!=2) return 125;
    if(strcmp(mode,"argv0-null")==0) {
        const char *args[]={0,"--command","config"};
        result=fixture_child_main(3,args);
    } else {
        result=fixture_child_main(argc-1,(const char *const *)(argv+1));
    }
    return broken || writes!=1 ? 125 : result;
}
