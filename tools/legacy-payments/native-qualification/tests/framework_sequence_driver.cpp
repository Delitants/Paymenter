#include "../native/framework_sequence.hpp"
#include <iostream>
using namespace paymenter_qa;
class B:public FrameworkRunBackend {
public:std::string mode;FrameworkRows rows;unsigned begins=0,calls=0,advances=0,reads=0,loss=0;bool failed=false,done=false;
    explicit B(std::string m):mode(m){}
    void current()override{}
    FrameworkRows audit(const std::string& slot)override {
        if(mode=="dirty-baseline" && slot=="initial")return {"other"};
        if(mode=="foreign-row" && slot=="F2-after")return {"other"};
        if(mode=="F5-mutated" && slot=="F5-after")return {};
        return rows;
    }
    FrameworkRows expected(const std::string& id)override{return id=="F1" || id=="F4"?FrameworkRows{"business-"+id,"journal-"+id}:FrameworkRows{};}
    void begin(const std::string&)override{++begins;}
    void caller(const std::string& id,const std::function<void()>& during)override{++calls;if(mode=="caller-failed" && id=="F2")throw SequenceError("authored-caller-failure");during();auto w=expected(id);if(id!="F5"){rows.insert(rows.end(),w.begin(),w.end());std::sort(rows.begin(),rows.end());}}
    bool barrier_ready()override{return mode!="missing-barrier";}
    FrameworkRows observe(const std::string& id,const std::string& slot)override{++reads;if(slot=="barrier")return mode=="visible-barrier"?FrameworkRows{"business-F4"}:FrameworkRows{};return mode=="split" && id=="F1"?FrameworkRows{"business-F1"}:expected(id);}
    void release_barrier()override{}
    void checkpoints(const std::string& id)override{if(mode=="injection-not-reached" && id=="F2")throw SequenceError("no-checkpoint");}
    void withhold_and_reconcile(const std::string&)override{++loss;if(mode=="recovery-failed")throw SequenceError("recovery-failed");}
    void advance(const std::string&)override{++advances;}
    void refusal()override{if(mode=="F5-not-reached")throw SequenceError("no-refusal");}
    void complete()override{done=true;}
    void fail()noexcept override{failed=true;}
};
int main(int argc,char**argv){try{if(argc!=2)return 2;std::string mode=argv[1];B b(mode);FixedFrameworkSequence controller(b);
    bool passed=false;try{controller.run();passed=true;}catch(const SequenceError&){}
    if(passed!=(mode=="normal" || mode=="one-shot") || b.done!=passed || b.failed==passed)return 3;
    if(passed && (b.begins!=4 || b.calls!=5 || b.advances!=4 || b.loss!=1 || b.reads!=5))return 4;
    if(mode=="caller-failed" && (b.calls!=2 || b.advances!=1))return 5;
    if(mode=="one-shot"){try{controller.run();return 6;}catch(const SequenceError&){}}
    return 0;
}catch(const std::exception&e){std::cerr<<e.what();return 1;}}
