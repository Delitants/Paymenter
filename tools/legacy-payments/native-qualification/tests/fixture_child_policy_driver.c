/* Portable authored syscall boundary, not kernel confinement evidence. */
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include "../native/fixture_child_policy.h"

extern int fixture_child_main(long, const char *const *);
extern int fixture_install_filter(void);
static int fail_setup,fail_no_new_privs,setup_stage,contract_broken;
long fixture_syscall6(long nr,long a,long b,long c,long d,long e,long f) {
    /* Model an old kernel: the newer seccomp syscall is NOT available.
     * Verify the production setup sequence and actual BPF argument, not a
     * permissive double that succeeds for any prctl call. */
    if(nr==157 && a==38 && b==1 && c==0 && d==0 && e==0 && f==0 && setup_stage==0) {
        setup_stage=fail_no_new_privs ? -1 : 1;
        return fail_no_new_privs ? -1 : 0;
    }
    if(nr==157 && a==22 && b==2 && c!=0 && d==0 && e==0 && f==0 && setup_stage==1) {
        const struct fixture_sock_fprog *program=(const struct fixture_sock_fprog *)c;
        if(program->len!=FIXTURE_FILTER_LEN || !program->filter ||
           memcmp(program->filter,fixture_filter,sizeof(fixture_filter))!=0) {
            contract_broken=1;return -1;
        }
        setup_stage=fail_setup ? -1 : 2;
        return fail_setup ? -1 : 0;
    }
    if(nr==1 && setup_stage==2) return write((int)a,(const void *)b,(size_t)c);
    contract_broken=1;return -1;
}
static unsigned policy(unsigned nr,unsigned arch,uint64_t fd,uint64_t count) {
    unsigned data[16]={0},acc=0; size_t pc=0,steps=0;
    data[0]=nr;data[1]=arch;data[4]=(unsigned)fd;data[5]=(unsigned)(fd>>32);
    data[8]=(unsigned)count;data[9]=(unsigned)(count>>32);
    while(pc<FIXTURE_FILTER_LEN && ++steps<256) {
        struct fixture_sock_filter ins=fixture_filter[pc++];
        if(ins.code==0x20) {if(ins.k%4 || ins.k/4>=16) return 0xffffffffU;acc=data[ins.k/4];}
        else if(ins.code==0x15) pc+=(acc==ins.k?ins.jt:ins.jf);
        else if(ins.code==0x25) pc+=(acc>ins.k?ins.jt:ins.jf);
        else if(ins.code==0x06) return ins.k;
        else return 0xffffffffU;
    }
    return 0xffffffffU;
}
int main(int argc,char **argv) {
    if(argc==6 && strcmp(argv[1],"policy")==0) {
        printf("%u\n",policy((unsigned)strtoul(argv[2],0,0),(unsigned)strtoul(argv[3],0,0),
                              strtoull(argv[4],0,0),strtoull(argv[5],0,0)));return 0;
    }
    if(argc<2) return 2;
    fail_setup=strcmp(argv[1],"setup-fail")==0;
    fail_no_new_privs=strcmp(argv[1],"no-new-privs-fail")==0;
    {
        int result=fixture_install_filter();
        if(contract_broken) return 125;
        if(result!=0) return 126;
        if(setup_stage!=2) return 125;
        result=fixture_child_main(argc-1,(const char *const *)(argv+1));
        return contract_broken ? 125 : result;
    }
}
