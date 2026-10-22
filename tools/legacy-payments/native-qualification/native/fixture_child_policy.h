/* Linux x86-64 SINGLE-THREAD child-only policy. No native acceptance.
 * Legacy SECCOMP_RET_KILL terminates the calling task, not a thread group.
 * This is valid only for the freestanding single-thread entry with no loader,
 * constructors or pre-filter thread creation; clone/fork/exec remain denied. */
#ifndef PAYMENTER_FIXTURE_CHILD_POLICY_H
#define PAYMENTER_FIXTURE_CHILD_POLICY_H
struct fixture_sock_filter { unsigned short code; unsigned char jt,jf; unsigned int k; };
struct fixture_sock_fprog { unsigned short len; const struct fixture_sock_filter *filter; };
/* cBPF: LD W ABS=0x20, JEQ K=0x15, JGT K=0x25, RET K=0x06.
 * struct seccomp_data: nr0, arch4, args[0]16, args[2]32 (little endian).
 * Full-width fd/count checks are essential: low-word truncation is unsafe. */
static const struct fixture_sock_filter fixture_filter[] = {
    {0x20,0,0,4},                   /* 0 arch */
    {0x15,1,0,0xc000003e},          /* 1 */
    {0x06,0,0,0x00000000},          /* 2 kill wrong ABI */
    {0x20,0,0,0},                   /* 3 syscall number */
    {0x15,0,1,60},                  /* 4 exit */
    {0x06,0,0,0x7fff0000},          /* 5 allow */
    {0x15,0,1,231},                 /* 6 exit_group */
    {0x06,0,0,0x7fff0000},          /* 7 allow */
    {0x15,1,0,1},                   /* 8 write only; x32 refuses */
    {0x06,0,0,0x00000000},          /* 9 kill */
    {0x20,0,0,20},                  /* 10 fd high word */
    {0x15,1,0,0},                   /* 11 */
    {0x06,0,0,0x00000000},          /* 12 */
    {0x20,0,0,36},                  /* 13 count high word */
    {0x15,1,0,0},                   /* 14 */
    {0x06,0,0,0x00000000},          /* 15 */
    {0x20,0,0,16},                  /* 16 fd low word */
    {0x15,0,4,1},                   /* 17 stdout? */
    {0x20,0,0,32},                  /* 18 count low word */
    {0x25,0,1,4096},                /* 19 too large? */
    {0x06,0,0,0x00000000},          /* 20 */
    {0x06,0,0,0x7fff0000},          /* 21 */
    {0x15,0,4,2},                   /* 22 stderr? */
    {0x20,0,0,32},                  /* 23 */
    {0x25,0,1,256},                 /* 24 */
    {0x06,0,0,0x00000000},          /* 25 */
    {0x06,0,0,0x7fff0000},          /* 26 */
    {0x06,0,0,0x00000000}           /* 27 all other descriptors */
};
#define FIXTURE_FILTER_LEN (sizeof(fixture_filter)/sizeof(fixture_filter[0]))
#endif
