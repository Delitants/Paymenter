// Fixed orchestration only. Backend observations must be issued from actual
// protected native resources; this state machine cannot issue an admitted run.
#pragma once
#include <algorithm>
#include <functional>
#include <stdexcept>
#include <string>
#include <vector>
namespace paymenter_qa {
class SequenceError:public std::runtime_error {public:explicit SequenceError(const char*s):std::runtime_error(s){}};
inline void sequence_guard(bool v,const char*s){if(!v)throw SequenceError(s);}
using FrameworkRows=std::vector<std::string>;
class FrameworkRunBackend {
public:
    virtual ~FrameworkRunBackend(){}
    virtual void current()=0;
    virtual FrameworkRows audit(const std::string& slot)=0;
    virtual FrameworkRows expected(const std::string& id)=0;
    virtual void begin(const std::string& id)=0;
    virtual void caller(const std::string& id,const std::function<void()>& during)=0;
    virtual bool barrier_ready()=0;
    virtual FrameworkRows observe(const std::string& id,const std::string& slot)=0;
    virtual void release_barrier()=0;
    virtual void checkpoints(const std::string& id)=0;
    virtual void withhold_and_reconcile(const std::string& id)=0;
    virtual void advance(const std::string& id)=0;
    virtual void refusal()=0;
    virtual void complete()=0;
    virtual void fail()noexcept=0;
};
class FixedFrameworkSequence {
    FrameworkRunBackend& backend_;bool consumed_=false;
public:
    explicit FixedFrameworkSequence(FrameworkRunBackend& b):backend_(b){}
    void run() {
        sequence_guard(!consumed_,"controller-reused");consumed_=true;
        try {
            backend_.current();sequence_guard(backend_.audit("initial").empty(),"controller-baseline-not-empty");
            FrameworkRows expected;
            for(const auto& id:{"F1","F2","F3","F4"}) {
                backend_.current();backend_.begin(id);bool barrier=false;
                backend_.caller(id,[&] {
                    backend_.current();
                    if(std::string(id)=="F4" && !barrier && backend_.barrier_ready()) {
                        sequence_guard(backend_.observe(id,"barrier").empty(),"controller-pre-return-visible");
                        backend_.release_barrier();barrier=true;
                    }
                });
                if(std::string(id)=="F4")sequence_guard(barrier,"controller-barrier-missed");
                backend_.checkpoints(id);
                auto wanted=backend_.expected(id);sequence_guard(backend_.observe(id,"after")==wanted,"controller-case-result");
                if(std::string(id)=="F1")backend_.withhold_and_reconcile(id);
                expected.insert(expected.end(),wanted.begin(),wanted.end());std::sort(expected.begin(),expected.end());
                sequence_guard(backend_.audit(std::string(id)+"-after")==expected,"controller-other-row-mutation");
                backend_.advance(id);
            }
            backend_.current();auto before=backend_.audit("F5-before");sequence_guard(before==expected,"controller-refusal-baseline");
            backend_.caller("F5",[&]{backend_.current();});backend_.refusal();
            sequence_guard(backend_.audit("F5-after")==before,"controller-refusal-mutated");
            backend_.current();backend_.complete();
        }catch(...){backend_.fail();throw;}
    }
};
}
