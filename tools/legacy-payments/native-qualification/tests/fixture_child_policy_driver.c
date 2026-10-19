/* Portable authored syscall boundary, not kernel confinement evidence. */
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include "../native/fixture_child_policy.h"

extern int fixture_child_main(long, const char *const *);
extern int fixture_install_filter(void);
static int fail_setup;
long fixture_syscall6(long nr,long a,long b,long c,long d,long e,long f) {
    (void)d;(void)e;(void)f;
    if(nr==157) return 0;
    if(nr==317) return fail_setup ? -1 : 0;
    if(nr==1) return write((int)a,(const void *)b,(size_t)c);
    return -1;
}
static unsigned policy(unsigned nr,unsigned arch,uint64_t fd,uint64_t count) {
    unsigned data[16]={0},acc=0; size_t pc=0,steps=0;
    data[0]=nr;data[1]=arch;data[4]=(unsigned)fd;data[5]=(unsigned)(fd>>32);
    data[8]=(unsigned)count;data[9]=(unsigned)(count>>32);
    while(pc<FIXTURE_FILTER_LEN && ++steps<256) {
        struct fixture_sock_filter ins=fixture_filter[pc++];
        if(ins.code==0x20) {if(ins.k%4 || ins.k/4>=16) return 0;acc=data[ins.k/4];}
        else if(ins.code==0x15) pc+=(acc==ins.k?ins.jt:ins.jf);
        else if(ins.code==0x25) pc+=(acc>ins.k?ins.jt:ins.jf);
        else if(ins.code==0x06) return ins.k;
        else return 0;
    }
    return 0;
}
int main(int argc,char **argv) {
    if(argc==6 && strcmp(argv[1],"policy")==0) {
        printf("%u\n",policy((unsigned)strtoul(argv[2],0,0),(unsigned)strtoul(argv[3],0,0),
                              strtoull(argv[4],0,0),strtoull(argv[5],0,0)));return 0;
    }
    if(argc<2) return 2;
    fail_setup=strcmp(argv[1],"setup-fail")==0;
    if(fixture_install_filter()!=0) return 126;
    return fixture_child_main(argc-1,(const char *const *)(argv+1));
}
