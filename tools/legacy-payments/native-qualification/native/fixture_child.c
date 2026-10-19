/* Freestanding authored candidate. Not a vendor module or installable config. */
#include "fixture_child_policy.h"
extern long fixture_syscall6(long,long,long,long,long,long,long);

int fixture_install_filter(void) {
    struct fixture_sock_fprog program;
    program.len=(unsigned short)FIXTURE_FILTER_LEN;
    program.filter=fixture_filter;
    if(fixture_syscall6(157,38,1,0,0,0,0)!=0) return -1;
    if(fixture_syscall6(317,1,0,(long)&program,0,0,0)!=0) return -1;
    return 0;
}

int fixture_child_main(long argc,const char *const *argv) {
    static const char expected[]="--offline-config-v0";
    static const char config[]="paymenter-fixture-offline-v0\npmtune=off\npmvalidate=off\npmusercreate=off\nfinancial=off\n";
    static const char refusal[]="fixture invocation refused\n";
    unsigned long i;
    int valid=argc==2;
    if(valid) {
        /* At most sizeof(expected) bytes; never examine argv[0], env or stdin. */
        for(i=0;i<sizeof(expected);++i) {
            if(argv[1][i]!=expected[i]) {valid=0;break;}
            if(expected[i]=='\0') break;
        }
    }
    if(valid) {
        return fixture_syscall6(1,1,(long)config,sizeof(config)-1,0,0,0)==
               (long)(sizeof(config)-1) ? 0 : 74;
    }
    return fixture_syscall6(1,2,(long)refusal,sizeof(refusal)-1,0,0,0)==
           (long)(sizeof(refusal)-1) ? 64 : 74;
}
