/* Separate authored XML candidate, never linked with fixture_child.c. */
#include "fixture_child_policy.h"
extern long fixture_syscall6(long,long,long,long,long,long,long);

int fixture_install_filter(void) {
    struct fixture_sock_fprog program;
    program.len=(unsigned short)FIXTURE_FILTER_LEN;
    program.filter=fixture_filter;
    if(fixture_syscall6(157,38,1,0,0,0,0)!=0) return -1;
    /* Linux3.10 interface, no newer-syscall attempt or fallback. */
    if(fixture_syscall6(157,22,2,(long)&program,0,0,0)!=0) return -1;
    return 0;
}

static int equal_fixed(const char *actual,const char *expected,unsigned long size) {
    unsigned long i;
    for(i=0;i<size;++i) {
        if(actual[i]!=expected[i]) return 0;
        if(expected[i]=='\0') return 1;
    }
    return 0;
}

int fixture_child_main(long argc,const char *const *argv) {
    static const char command[]="--command";
    static const char name[]="config";
    static const char config[]="<doc><feature><pmtune>off</pmtune><pmvalidate>off</pmvalidate><pmusercreate>off</pmusercreate></feature></doc>";
    static const char refusal[]="fixture invocation refused\n";
    /* Never examine argv[0], environment or stdin. Check argc before argv. */
    if(argc==3 && equal_fixed(argv[1],command,sizeof(command)) &&
                  equal_fixed(argv[2],name,sizeof(name))) {
        return fixture_syscall6(1,1,(long)config,sizeof(config)-1,0,0,0)==
               (long)(sizeof(config)-1) ? 0 : 74;
    }
    return fixture_syscall6(1,2,(long)refusal,sizeof(refusal)-1,0,0,0)==
           (long)(sizeof(refusal)-1) ? 64 : 74;
}
